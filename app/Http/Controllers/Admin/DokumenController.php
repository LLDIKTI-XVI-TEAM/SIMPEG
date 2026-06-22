<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DokumenController extends Controller
{
    public static $dokumen = [
        [
            'id' => 1,
            'jenis' => 'SK Pangkat',
            'nama' => 'SK Kenaikan Pangkat Penata Tkt. I',
            'nomor' => 'SK-882-KP-2024',
            'tanggal' => '2024-04-01',
            'kategori' => 'sk_kepegawaian',
            'kategori_label' => 'SK Kepegawaian',
            'file_path' => 'employees/docs/198503122010011001_sk_pangkat_2024.pdf',
            'file_size' => '1.2 MB',
            'deskripsi' => 'SK Kenaikan Pangkat Penata Tingkat I Golongan Ruang III/d atas nama Ahmad Fauzi.'
        ],
        [
            'id' => 2,
            'jenis' => 'SK Jabatan',
            'nama' => 'SK Pengangkatan Jabatan Analis Kepegawaian',
            'nomor' => 'SK-104-JAB-2022',
            'tanggal' => '2022-08-15',
            'kategori' => 'sk_kepegawaian',
            'kategori_label' => 'SK Kepegawaian',
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
            'kategori' => 'sk_kepegawaian',
            'kategori_label' => 'SK Kepegawaian',
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
            'kategori' => 'pendidikan',
            'kategori_label' => 'Pendidikan',
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
            'kategori' => 'identitas',
            'kategori_label' => 'Identitas & KK',
            'file_path' => 'employees/docs/198503122010011001_ktp.pdf',
            'file_size' => '620 KB',
            'deskripsi' => 'KTP sesuai data kependudukan yang berlaku.'
        ],
        [
            'id' => 6,
            'jenis' => 'KK',
            'nama' => 'Kartu Keluarga (KK)',
            'nomor' => '3171-7403-8803-0001',
            'tanggal' => '2021-05-10',
            'kategori' => 'identitas',
            'kategori_label' => 'Identitas & KK',
            'file_path' => 'employees/docs/198503122010011001_kk.pdf',
            'file_size' => '580 KB',
            'deskripsi' => 'Kartu Keluarga terbaru.'
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
            'berkas' => 'required|file|mimes:pdf|max:10240',
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
