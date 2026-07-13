<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeDocumentController extends Controller
{
    /**
     * Mengembalikan daftar dokumen arsip milik pegawai, bisa difilter per kategori.
     * Digunakan oleh dropdown "Pilih dari Arsip" di form tambah riwayat.
     */
    public function index(Employee $employee, Request $request): JsonResponse
    {
        $kategori = $request->query('kategori');

        $query = $employee->documents()
            ->when($kategori, fn ($q) => $q->where('jenis_dokumen', $kategori))
            ->orderByDesc('tanggal_dokumen')
            ->orderByDesc('created_at');

        $documents = $query->get(['id', 'nama_dokumen', 'nomor_dokumen', 'tanggal_dokumen', 'file_path', 'jenis_dokumen'])
            ->map(fn ($doc) => [
                'id' => $doc->id,
                'nama_dokumen' => $doc->nama_dokumen,
                'nomor_dokumen' => $doc->nomor_dokumen,
                'tanggal' => $doc->tanggal_dokumen?->format('d-m-Y'),
                'file_path' => $doc->file_path,
                'jenis_dokumen' => $doc->jenis_dokumen,
            ]);

        return response()->json([
            'employee_id' => $employee->id,
            'documents' => $documents,
        ]);
    }
}
