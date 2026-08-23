<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\StoreBerkasLainnyaAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreBerkasLainnyaRequest;
use App\Models\Employee;
use App\Support\Documents\DocumentCategory;
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

    /**
     * Mengunggah berkas lainnya (KTP, KK, Ijazah, dll.) milik pegawai
     * langsung dari halaman detail pegawai.
     *
     * Hanya kategori non-SK yang diizinkan: ijazah, ktp_kk, lainnya.
     * File disimpan ke disk privat di folder {employee_id}/{kategori}/ agar aksesnya
     * selalu melewati endpoint yang menerapkan otorisasi dan scope pegawai.
     */
    public function storeBerkasLainnya(
        StoreBerkasLainnyaRequest $request,
        Employee $employee,
        StoreBerkasLainnyaAction $action,
    ): JsonResponse {
        $document = $action->execute(
            $employee,
            $request->validated(),
            $request->file('berkas'),
            $request,
        );

        return response()->json([
            'message' => 'Berkas berhasil diunggah.',
            'document' => [
                'id' => $document->id,
                'nama_dokumen' => $document->nama_dokumen,
                'jenis_dokumen' => $document->jenis_dokumen,
                'kategori_label' => DocumentCategory::label($document->jenis_dokumen),
                'nomor_dokumen' => $document->nomor_dokumen,
                'tanggal_dokumen' => $document->tanggal_dokumen?->format('d-m-Y'),
                'file_size' => $document->fileSizeLabel(),
                'file_tersedia' => true,
                'keterangan' => $document->keterangan,
                'detail_url' => route('dokumen.show', $document->id),
                'download_url' => route('dokumen.download', $document->id),
            ],
        ], 201);
    }
}
