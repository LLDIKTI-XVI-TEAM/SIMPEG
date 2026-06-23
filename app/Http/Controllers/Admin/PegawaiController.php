<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;

class PegawaiController extends Controller
{
    public static $pegawaiList = [
        [
            'id' => 1,
            'nama' => 'Ahmad Fauzi',
            'nip' => '19850312201001 1 001',
            'nik' => '3273251203850002',
            'kk' => '3273250102120045',
            'tempat_lahir' => 'Bandung',
            'tanggal_lahir' => '1985-03-12',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'status_kawin' => 'Kawin',
            'golongan_darah' => 'O',
            'alamat' => 'Jl. Buah Batu No. 120, Lengkong, Bandung',
            'telepon' => '081234567890',
            'telepon_rumah' => '0227301234',
            'email' => 'ahmadfauzi@gmail.com',
            'email_dinas' => 'ahmadfauzi@lldikti16.go.id',
            'jabatan' => 'Analis Kepegawaian',
            'pangkat' => 'Penata Tkt. I',
            'kelas_jabatan' => '8',
            'unit' => 'Bag. Umum',
            'golongan' => 'III/c',
            'jenis' => 'PNS',
            'tmt' => '2010-10-01',
            'status' => 'aktif',
            'dok' => 'ok',
            'pendidikan_terakhir' => 'Sarjana (S1)',
            'prodi_pendidikan' => 'Manajemen Sumber Daya Manusia',
            'jenis_pengangkatan' => 'PNS Formasi Umum',
            'nomor_sk' => 'SK-882-KP-2024',
            'tanggal_sk' => '2024-03-15',
            'foto' => 'ahmad_fauzi.png',
            'kinerja_baik' => true,
            'atasan_nama' => 'Yudha Prasetya, M.Kom.',
            'atasan_nip' => '19780520200212 1 002',
            'atasan_jabatan' => 'Kepala Bagian Umum'
        ],
        [
            'id' => 2,
            'nama' => 'Siti Rahayu',
            'nip' => '19901120201501 2 003',
            'nik' => '3171052011900003',
            'kk' => '3171050204160012',
            'tempat_lahir' => 'Jakarta',
            'tanggal_lahir' => '1990-11-20',
            'jenis_kelamin' => 'Perempuan',
            'agama' => 'Islam',
            'status_kawin' => 'Kawin',
            'golongan_darah' => 'A',
            'alamat' => 'Jl. Tebet Barat Dalam Raya No. 45, Tebet, Jakarta Selatan',
            'telepon' => '085298765432',
            'telepon_rumah' => '0218305678',
            'email' => 'sitirahayu@gmail.com',
            'email_dinas' => 'sitirahayu@lldikti16.go.id',
            'jabatan' => 'Analis Ahli Madya',
            'pangkat' => 'Pemula Tkt. I',
            'kelas_jabatan' => '8',
            'unit' => 'Bag. Keuangan',
            'golongan' => 'II/d',
            'jenis' => 'PNS',
            'tmt' => '2015-01-01',
            'status' => 'aktif',
            'dok' => 'warn',
            'pendidikan_terakhir' => 'Magister (S2)',
            'prodi_pendidikan' => 'Akuntansi Sektor Publik',
            'jenis_pengangkatan' => 'PNS Formasi Umum',
            'nomor_sk' => 'SK-104-JAB-2022',
            'tanggal_sk' => '2022-08-01',
            'foto' => null,
            'kinerja_baik' => true,
            'atasan_nama' => 'Dr. Hendra Wijaya, M.E.',
            'atasan_nip' => '19750912199903 1 001',
            'atasan_jabatan' => 'Kepala Bagian Keuangan'
        ],
        [
            'id' => 3,
            'nama' => 'Sabrina Rossa Adriani Wibowo',
            'nip' => '20261210820500 0 04',
            'nik' => '3578021008980005',
            'kk' => '3578021511190089',
            'tempat_lahir' => 'Surabaya',
            'tanggal_lahir' => '1998-08-10',
            'jenis_kelamin' => 'Perempuan',
            'agama' => 'Katolik',
            'status_kawin' => 'Belum Kawin',
            'golongan_darah' => 'AB',
            'alamat' => 'Griya Kertajaya Indah Regency Blok B-12, Sukolilo, Surabaya',
            'telepon' => '081285066001',
            'telepon_rumah' => null,
            'email' => 'sabrinarossa24@gmail.com',
            'email_dinas' => 'sabrina.rossa@lldikti16.go.id',
            'jabatan' => 'Analis SDM Aparatur Ahli Pertama',
            'pangkat' => 'Pemula Tkt. I',
            'kelas_jabatan' => '8',
            'unit' => 'Bag. SDM',
            'golongan' => 'III/a',
            'jenis' => 'PNS',
            'tmt' => '2026-01-01',
            'status' => 'aktif',
            'dok' => 'ok',
            'pendidikan_terakhir' => 'Sarjana (S1)',
            'prodi_pendidikan' => 'Administrasi Negara',
            'jenis_pengangkatan' => 'PNS Formasi Umum',
            'nomor_sk' => 'SK-220-PNS-2026',
            'tanggal_sk' => '2025-12-15',
            'foto' => 'sabrina_rossa.png',
            'kinerja_baik' => true,
            'atasan_nama' => 'Rina Amalia, S.Sos., M.M.',
            'atasan_nip' => '19810405200501 2 004',
            'atasan_jabatan' => 'Kepala Bagian SDM'
        ],
        [
            'id' => 4,
            'nama' => 'Cimma Sari Oktariani Di Silapu',
            'nip' => '26110820520600 0 04',
            'nik' => '7371120809960002',
            'kk' => '7371121010180034',
            'tempat_lahir' => 'Makassar',
            'tanggal_lahir' => '1996-09-08',
            'jenis_kelamin' => 'Perempuan',
            'agama' => 'Kristen',
            'status_kawin' => 'Belum Kawin',
            'golongan_darah' => 'B',
            'alamat' => 'Kompleks IDI Blok GA No. 7, Panakkukang, Makassar',
            'telepon' => '081258206006',
            'telepon_rumah' => null,
            'email' => 'sikaemma@gmail.com',
            'email_dinas' => 'cimasari@lldikti16.go.id',
            'jabatan' => 'Pranata SDM Terampil',
            'pangkat' => 'Pengatur DO',
            'kelas_jabatan' => '6',
            'unit' => 'Bag. IT',
            'golongan' => 'III/c',
            'jenis' => 'PNS',
            'tmt' => '2020-10-01',
            'status' => 'aktif',
            'dok' => 'danger',
            'pendidikan_terakhir' => 'Diploma III (D3)',
            'prodi_pendidikan' => 'Teknik Informatika',
            'jenis_pengangkatan' => 'PNS Formasi Umum',
            'nomor_sk' => 'SK-551-KP-2020',
            'tanggal_sk' => '2020-09-10',
            'foto' => null,
            'kinerja_baik' => false,
            'atasan_nama' => 'Irwan Santoso, M.T.',
            'atasan_nip' => '19800215200604 1 003',
            'atasan_jabatan' => 'Kepala Bagian IT'
        ],
        [
            'id' => 5,
            'nama' => 'Nurarningsih Dumbea, S.P.',
            'nip' => '19880123202 1 005',
            'nik' => '7501024301880001',
            'kk' => '7501021405100023',
            'tempat_lahir' => 'Gorontalo',
            'tanggal_lahir' => '1988-01-23',
            'jenis_kelamin' => 'Perempuan',
            'agama' => 'Islam',
            'status_kawin' => 'Kawin',
            'golongan_darah' => 'O',
            'alamat' => 'Jl. Panjaitan No. 89, Kota Tengah, Gorontalo',
            'telepon' => '082302200526',
            'telepon_rumah' => '0435821234',
            'email' => 'rainingdumbea47@gmail.com',
            'email_dinas' => 'nurarningsih@lldikti16.go.id',
            'jabatan' => 'Pejabat Lelang Operational',
            'pangkat' => 'Penata Tkt. I',
            'kelas_jabatan' => '7',
            'unit' => 'Bag. Umum',
            'golongan' => 'III/b',
            'jenis' => 'PPPK',
            'tmt' => '2021-11-01',
            'status' => 'aktif',
            'dok' => 'ok',
            'pendidikan_terakhir' => 'Sarjana (S1)',
            'prodi_pendidikan' => 'Agribisnis',
            'jenis_pengangkatan' => 'PPPK Tahap I',
            'nomor_sk' => 'SK-091-PPPK-2021',
            'tanggal_sk' => '2021-10-25',
            'foto' => null,
            'kinerja_baik' => true,
            'atasan_nama' => 'Yudha Prasetya, M.Kom.',
            'atasan_nip' => '19780520200212 1 002',
            'atasan_jabatan' => 'Kepala Bagian Umum'
        ],
        [
            'id' => 6,
            'nama' => 'Nadia Kusuma',
            'nip' => '19950822202001 2 002',
            'nik' => '3204286208950004',
            'kk' => '3204280203170056',
            'tempat_lahir' => 'Soreang',
            'tanggal_lahir' => '1995-08-22',
            'jenis_kelamin' => 'Perempuan',
            'agama' => 'Islam',
            'status_kawin' => 'Belum Kawin',
            'golongan_darah' => 'B',
            'alamat' => 'Kopo Elok Regency Blok C-3, Soreang, Bandung',
            'telepon' => '081299887766',
            'telepon_rumah' => null,
            'email' => 'nadiakusuma@gmail.com',
            'email_dinas' => 'nadiakusuma@lldikti16.go.id',
            'jabatan' => 'Pengelola Kepegawaian',
            'pangkat' => 'Pengatur',
            'kelas_jabatan' => '6',
            'unit' => 'Bag. SDM',
            'golongan' => 'II/c',
            'jenis' => 'PPPK',
            'tmt' => '2020-01-01',
            'status' => 'cuti',
            'dok' => 'ok',
            'pendidikan_terakhir' => 'Diploma III (D3)',
            'prodi_pendidikan' => 'Administrasi Perkantoran',
            'jenis_pengangkatan' => 'PPPK Tahap II',
            'nomor_sk' => 'SK-042-PPPK-2020',
            'tanggal_sk' => '2019-12-20',
            'foto' => null,
            'kinerja_baik' => true,
            'atasan_nama' => 'Rina Amalia, S.Sos., M.M.',
            'atasan_nip' => '19810405200501 2 004',
            'atasan_jabatan' => 'Kepala Bagian SDM'
        ],
        [
            'id' => 7,
            'nama' => 'Yucna Dara, S.P., M.M.',
            'nip' => '19840120099 2 002',
            'nik' => '3273102001840003',
            'kk' => '3273101509120087',
            'tempat_lahir' => 'Garut',
            'tanggal_lahir' => '1984-01-20',
            'jenis_kelamin' => 'Perempuan',
            'agama' => 'Islam',
            'status_kawin' => 'Kawin',
            'golongan_darah' => 'A',
            'alamat' => 'Perum Tarogong Indah No. A-9, Tarogong Kidul, Garut',
            'telepon' => '081284920002',
            'telepon_rumah' => '0262234567',
            'email' => 'hanaryog101@gmail.com',
            'email_dinas' => 'yucnadara@lldikti16.go.id',
            'jabatan' => 'Analis Ahli Pertama',
            'pangkat' => 'Penata Tkt. I',
            'kelas_jabatan' => '8',
            'unit' => 'Bag. Keuangan',
            'golongan' => 'III/b',
            'jenis' => 'PNS',
            'tmt' => '2009-01-01',
            'status' => 'aktif',
            'dok' => 'warn',
            'pendidikan_terakhir' => 'Magister (S2)',
            'prodi_pendidikan' => 'Magister Manajemen Keuangan',
            'jenis_pengangkatan' => 'PNS Formasi Umum',
            'nomor_sk' => 'SK-312-PNS-2009',
            'tanggal_sk' => '2008-12-10',
            'foto' => null,
            'kinerja_baik' => true,
            'atasan_nama' => 'Dr. Hendra Wijaya, M.E.',
            'atasan_nip' => '19750912199903 1 001',
            'atasan_jabatan' => 'Kepala Bagian Keuangan'
        ]
    ];

    public function index(Request $request)
    {
        $pegawaiData = Employee::with(['jenisPegawai'])->paginate(10);
        return view('admin.pegawai.index', compact('pegawaiData'));
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
            'nik' => 'required|string|size:16',
            'kk' => 'nullable|string|size:16',
            'jabatan' => 'required|string|max:255',
            'pangkat' => 'required|string|max:100',
            'kelas_jabatan' => 'required|string|max:10',
            'unit' => 'required|string',
            'golongan' => 'required|string',
            'jenis' => 'required|string',
            'tmt' => 'required|string',
            'email_dinas' => 'required|email|max:255',
            'email' => 'nullable|email|max:255',
            'foto' => 'nullable|image|max:10240|mimes:jpg,png',
            'file_sk' => 'nullable|file|max:10240|mimes:pdf,jpg,png',
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
            'nik' => 'required|string|size:16',
            'kk' => 'nullable|string|size:16',
            'jabatan' => 'required|string|max:255',
            'pangkat' => 'required|string|max:100',
            'kelas_jabatan' => 'required|string|max:10',
            'unit' => 'required|string',
            'golongan' => 'required|string',
            'jenis' => 'required|string',
            'tmt' => 'required|string',
            'email_dinas' => 'required|email|max:255',
            'email' => 'nullable|email|max:255',
            'foto' => 'nullable|image|max:10240|mimes:jpg,png',
            'file_sk' => 'nullable|file|max:10240|mimes:pdf,jpg,png',
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
