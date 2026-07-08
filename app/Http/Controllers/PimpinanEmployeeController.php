<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PimpinanEmployeeController extends Controller
{
    public function index()
    {
        $employees = [
            ['id' => Str::uuid(), 'nip' => '198001012005011001', 'nama' => 'Budi Santoso', 'golongan' => 'IV/a', 'status' => 'aktif', 'jabatan' => 'Analis Kepegawaian Muda'],
            ['id' => Str::uuid(), 'nip' => '198205122008012003', 'nama' => 'Siti Aminah', 'golongan' => 'III/d', 'status' => 'aktif', 'jabatan' => 'Perancang Peraturan'],
            ['id' => Str::uuid(), 'nip' => '197511202000121001', 'nama' => 'Andi Darmawan', 'golongan' => 'IV/c', 'status' => 'aktif', 'jabatan' => 'Auditor Utama'],
            ['id' => Str::uuid(), 'nip' => '198509152010122002', 'nama' => 'Rina Mulyani', 'golongan' => 'III/c', 'status' => 'cuti', 'jabatan' => 'Penelaah Teknis'],
            ['id' => Str::uuid(), 'nip' => '199003052015041001', 'nama' => 'Herman Susilo', 'golongan' => 'III/a', 'status' => 'tugas_belajar', 'jabatan' => 'Pranata Komputer'],
        ];

        return view('pimpinan.pegawai.index', compact('employees'));
    }

    public function show($employee)
    {
        $employeeData = [
            'id' => $employee,
            'nip' => '198001012005011001',
            'nama' => 'Budi Santoso, S.Kom., M.T.',
            'tempat_lahir' => 'Jakarta',
            'tanggal_lahir' => '1980-01-01',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'golongan' => 'IV/a',
            'jabatan' => 'Analis Kepegawaian Muda',
            'status' => 'aktif',
            'unit_kerja' => 'Bagian Kepegawaian',
            'pendidikan_terakhir' => 'S2 Teknik Informatika',
            'alamat' => 'Jl. Merdeka No. 123, Jakarta Pusat',
            'nik' => '3174123456789012',
            'npwp' => '12.345.678.9-012.000',
            'telepon' => '081234567890',
            'email' => 'budi.santoso@example.com',
        ];

        $riwayatKepangkatan = [
            ['golongan' => 'IV/a', 'tmt' => '2022-04-01', 'sk' => '88/KEP/2022'],
            ['golongan' => 'III/d', 'tmt' => '2018-04-01', 'sk' => '77/KEP/2018'],
        ];

        $riwayatJabatan = [
            ['jabatan' => 'Analis Kepegawaian Muda', 'unit' => 'Bagian Kepegawaian', 'tmt' => '2020-01-01', 'sk' => '123/KEP/2020', 'kelas' => '9'],
            ['jabatan' => 'Analis Kepegawaian Pertama', 'unit' => 'Bagian Kepegawaian', 'tmt' => '2015-01-01', 'sk' => '45/KEP/2015', 'kelas' => '8'],
        ];

        $riwayatKgb = [
            ['tmt' => '2023-01-01', 'gaji' => 'Rp 4.500.000', 'sk' => '99/KGB/2023'],
            ['tmt' => '2021-01-01', 'gaji' => 'Rp 4.200.000', 'sk' => '55/KGB/2021'],
        ];

        $hukumanDisiplin = [
            ['jenis' => 'Teguran Lisan', 'tanggal' => '2019-05-10', 'keterangan' => 'Terlambat masuk 3 hari berturut-turut', 'status' => 'Selesai'],
        ];

        $riwayatPendidikan = [
            ['tingkat' => 'S2', 'jurusan' => 'Teknik Informatika', 'institusi' => 'Universitas Indonesia', 'tahun' => '2018'],
            ['tingkat' => 'S1', 'jurusan' => 'Sistem Informasi', 'institusi' => 'Universitas Gadjah Mada', 'tahun' => '2005'],
        ];
        
        $dokumen = [
            ['nama' => 'SK CPNS', 'tanggal' => '2005-01-01'],
            ['nama' => 'SK PNS', 'tanggal' => '2006-01-01'],
            ['nama' => 'Ijazah S1', 'tanggal' => '2005-08-01'],
            ['nama' => 'Ijazah S2', 'tanggal' => '2018-08-01'],
        ];

        $dataPengangkatan = [
            'jenis' => 'PNS',
            'tmt_cpns' => '2005-01-01',
            'sk_cpns' => '01/CPNS/2005',
            'tmt_pns' => '2006-01-01',
            'sk_pns' => '02/PNS/2006',
        ];

        $infoOtomatis = [
            'kp_berikutnya' => '2026-04-01',
            'kgb_berikutnya' => '2025-01-01',
            'pensiun' => '2038-01-01',
        ];

        return view('pimpinan.pegawai.show', compact('employeeData', 'riwayatKepangkatan', 'riwayatJabatan', 'riwayatKgb', 'hukumanDisiplin', 'riwayatPendidikan', 'dokumen', 'dataPengangkatan', 'infoOtomatis'));
    }
}
