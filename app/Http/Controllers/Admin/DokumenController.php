<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DokumenController extends Controller
{
    public function index()
    {
        $pegawaiList = Employee::orderBy('nama_lengkap')->get(['id', 'nama_lengkap', 'nip']);
        $documents = Document::with([
            'employee.positionHistories' => fn ($query) => $query
                ->with('unitKerja')
                ->orderByDesc('is_latest')
                ->orderByDesc('tmt_jabatan'),
        ])->latest()->get();

        return view('admin.dokumen.index', compact('pegawaiList', 'documents'));
    }

    public function show($id)
    {
        $document = Document::with([
            'employee.positionHistories' => fn ($query) => $query
                ->with('unitKerja')
                ->orderByDesc('is_latest')
                ->orderByDesc('tmt_jabatan'),
        ])->findOrFail($id);

        $currentPosition = $document->employee->positionHistories->first();
        $unit = $currentPosition?->unitKerja?->nama ?? '-';

        $kategoriLabels = [
            'sk_pengangkatan' => 'SK Pengangkatan',
            'sk_pangkat' => 'SK Kenaikan Pangkat',
            'sk_jabatan' => 'SK Kenaikan Jabatan',
            'sk_kgb' => 'SK KGB',
            'ijazah' => 'Ijazah',
            'ktp_kk' => 'KTP & KK',
            'lainnya' => 'Lainnya',
        ];

        $doc = [
            'id' => $document->id,
            'nama' => $document->nama_dokumen,
            'kategori_label' => $kategoriLabels[$document->jenis_dokumen] ?? 'Lainnya',
            'file_size' => '1.5 MB', // mock size
            'nama_pegawai' => $document->employee->nama_lengkap,
            'nip_pegawai' => $document->employee->nip,
            'unit_pegawai' => $unit,
            'nomor' => $document->nomor_dokumen ?? '-',
            'tanggal' => $document->tanggal_dokumen ? $document->tanggal_dokumen->format('Y-m-d') : '-',
            'deskripsi' => $document->keterangan ?? '-',
            'file_path' => $document->file_path,
        ];

        return view('admin.dokumen.show', compact('doc'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'nama_dokumen' => 'required|string|max:255',
            'nomor_dokumen' => 'required|string|max:100',
            'tanggal_terbit' => 'required|date',
            'kategori_dokumen' => 'required|string',
            'pegawai_id' => 'required|uuid|exists:employees,id',
            'berkas' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
        ]);

        $employee = Employee::findOrFail($request->input('pegawai_id'));

        $filePath = $request->file('berkas')->store('employees/documents', 'public');

        Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => $request->input('kategori_dokumen'),
            'nama_dokumen' => $request->input('nama_dokumen'),
            'nomor_dokumen' => $request->input('nomor_dokumen'),
            'tanggal_dokumen' => $request->input('tanggal_terbit'),
            'file_path' => $filePath,
            'keterangan' => $request->input('deskripsi'),
        ]);

        return redirect()->route('dokumen')
            ->with('success', 'Dokumen "' . $request->input('nama_dokumen') . '" berhasil diunggah.');
    }

    public function download($id)
    {
        $doc = Document::findOrFail($id);

        if (!Storage::disk('public')->exists($doc->file_path)) {
            abort(404);
        }

        return Storage::disk('public')->download($doc->file_path, basename($doc->file_path));
    }
}

