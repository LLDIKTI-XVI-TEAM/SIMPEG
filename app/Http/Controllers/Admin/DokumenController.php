<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DokumenController extends Controller
{
    public static $dokumen = [
        [
            'id' => 1,
            'jenis' => 'SK Kenaikan Pangkat',
            'nama' => 'SK Kenaikan Pangkat Penata Tkt. I',
            'nomor' => 'SK-882-KP-2024',
            'tanggal' => '2024-04-01',
            'kategori' => 'sk_pangkat',
            'kategori_label' => 'SK Kenaikan Pangkat',
            'nama_pegawai' => 'Ahmad Fauzi',
            'nip_pegawai' => '19850312201001 1 001',
            'unit_pegawai' => 'Bag. Umum',
            'file_path' => 'employees/docs/198503122010011001_sk_pangkat_2024.pdf',
            'file_size' => '1.2 MB',
            'deskripsi' => 'SK Kenaikan Pangkat Penata Tingkat I Golongan Ruang III/d atas nama Ahmad Fauzi.'
        ],
        [
            'id' => 2,
            'jenis' => 'SK Kenaikan Jabatan',
            'nama' => 'SK Pengangkatan Jabatan Analis Kepegawaian',
            'nomor' => 'SK-104-JAB-2022',
            'tanggal' => '2022-08-15',
            'kategori' => 'sk_jabatan',
            'kategori_label' => 'SK Kenaikan Jabatan',
            'nama_pegawai' => 'Ahmad Fauzi',
            'nip_pegawai' => '19850312201001 1 001',
            'unit_pegawai' => 'Bag. Umum',
            'file_path' => 'employees/docs/198503122010011001_sk_jabatan_2022.pdf',
            'file_size' => '850 KB',
            'deskripsi' => 'SK Pengangkatan Pertama kali dalam Jabatan Fungsional Analis Kepegawaian Ahli Pertama.'
        ],
        [
            'id' => 3,
            'jenis' => 'SK KGB',
            'nama' => 'SK Kenaikan Gaji Berkala 2025',
            'nomor' => 'KGB-334-VII-2025',
            'tanggal' => '2025-07-01',
            'kategori' => 'sk_kgb',
            'kategori_label' => 'SK KGB',
            'nama_pegawai' => 'Ahmad Fauzi',
            'nip_pegawai' => '19850312201001 1 001',
            'unit_pegawai' => 'Bag. Umum',
            'file_path' => 'employees/docs/198503122010011001_sk_kgb_2025.pdf',
            'file_size' => '420 KB',
            'deskripsi' => 'Surat Keterangan Kenaikan Gaji Berkala Reguler tahun berjalan 2025.'
        ],
        [
            'id' => 4,
            'jenis' => 'Ijazah',
            'nama' => 'Ijazah Sarjana (S1) Manajemen',
            'nomor' => 'IJZ-S1-MAN-2007',
            'tanggal' => '2007-09-20',
            'kategori' => 'ijazah',
            'kategori_label' => 'Ijazah',
            'nama_pegawai' => 'Ahmad Fauzi',
            'nip_pegawai' => '19850312201001 1 001',
            'unit_pegawai' => 'Bag. Umum',
            'file_path' => 'employees/docs/198503122010011001_ijazah_s1.pdf',
            'file_size' => '2.1 MB',
            'deskripsi' => 'Ijazah Sarjana S1 Program Studi Manajemen dari Universitas Sam Ratulangi.'
        ],
        [
            'id' => 5,
            'jenis' => 'KTP',
            'nama' => 'Kartu Tanda Penduduk (KTP)',
            'nomor' => '3171-7403-8803-0001',
            'tanggal' => '2021-05-10',
            'kategori' => 'ktp_kk',
            'kategori_label' => 'KTP & KK',
            'nama_pegawai' => 'Siti Rahayu',
            'nip_pegawai' => '19901120201501 2 003',
            'unit_pegawai' => 'Bag. Keuangan',
            'file_path' => 'employees/docs/199011202015012003_ktp.pdf',
            'file_size' => '620 KB',
            'deskripsi' => 'KTP atas nama Siti Rahayu.'
        ],
        [
            'id' => 6,
            'jenis' => 'KK',
            'nama' => 'Kartu Keluarga (KK)',
            'nomor' => '3171-7403-8803-0002',
            'tanggal' => '2021-05-10',
            'kategori' => 'ktp_kk',
            'kategori_label' => 'KTP & KK',
            'nama_pegawai' => 'Siti Rahayu',
            'nip_pegawai' => '19901120201501 2 003',
            'unit_pegawai' => 'Bag. Keuangan',
            'file_path' => 'employees/docs/199011202015012003_kk.pdf',
            'file_size' => '580 KB',
            'deskripsi' => 'Kartu Keluarga terbaru atas nama kepala keluarga Siti Rahayu.'
        ],
        [
            'id' => 7,
            'jenis' => 'SK Pengangkatan',
            'nama' => 'SK Pengangkatan PNS 2026',
            'nomor' => 'SK-220-PNS-2026',
            'tanggal' => '2026-01-01',
            'kategori' => 'sk_pengangkatan',
            'kategori_label' => 'SK Pengangkatan',
            'nama_pegawai' => 'Sabrina Rossa Adriani Wibowo',
            'nip_pegawai' => '20261210820500 0 04',
            'unit_pegawai' => 'Bag. SDM',
            'file_path' => 'employees/docs/20261210820500004_sk_pns.pdf',
            'file_size' => '1.5 MB',
            'deskripsi' => 'SK Pengangkatan PNS atas nama Sabrina Rossa.'
        ],
    ];

    public function index()
    {
        return view('admin.dokumen.index');
    }

    public function show($id)
    {
        $doc = collect(self::$dokumen)->firstWhere('id', (int)$id);
        if (!$doc) {
            abort(404);
        }
        return view('admin.dokumen.show', compact('doc'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'nama_dokumen' => 'required|string|max:255',
            'nomor_dokumen' => 'required|string|max:100',
            'tanggal_terbit' => 'required|date',
            'kategori_dokumen' => 'required|string',
            'pegawai_id' => 'required',
            'berkas' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
        ]);

        return redirect()->route('dokumen')
            ->with('success', 'Dokumen "' . $request->input('nama_dokumen') . '" berhasil diunggah.');
    }

    public function download($id)
    {
        $doc = collect(self::$dokumen)->firstWhere('id', (int)$id);
        if (!$doc) {
            abort(404);
        }
        
        $filename = basename($doc['file_path']);
        
        // Simulating direct content return for mockup/demo download
        return response()->streamDownload(function () use ($doc) {
            echo "Mock PDF file content for " . $doc['nama'];
        }, $filename, [
            'Content-type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
