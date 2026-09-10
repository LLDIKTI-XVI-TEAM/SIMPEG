<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\DeleteBerkasLainnyaAction;
use App\Actions\Documents\StoreBerkasLainnyaAction;
use App\Actions\Documents\UpdateBerkasLainnyaAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\DeleteBerkasLainnyaRequest;
use App\Http\Requests\Documents\StoreBerkasLainnyaRequest;
use App\Http\Requests\Documents\UpdateBerkasLainnyaRequest;
use App\Models\Document;
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
        $viewer = $request->user();
        $kategori = $request->query('kategori');
        // P1 privacy: pimpinan tetap 200 tapi ktp_kk excluded.
        if ($viewer !== null && $viewer->getEffectiveRole() === 'pimpinan' && $kategori === 'ktp_kk') {
            abort(404);
        }

        $query = $employee->documents()
            ->when($kategori, fn ($q) => $q->where('jenis_dokumen', $kategori))
            ->when($viewer !== null && $viewer->getEffectiveRole() === 'pimpinan', fn ($q) => $q->whereIn('jenis_dokumen', DocumentCategory::visibleToPimpinanKeys()))
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
            'document' => $this->payload($document),
        ], 201);
    }

    /** Memperbarui Berkas Lainnya milik pegawai tanpa membuka mutasi dari arsip pusat. */
    public function updateBerkasLainnya(
        UpdateBerkasLainnyaRequest $request,
        Employee $employee,
        Document $document,
        UpdateBerkasLainnyaAction $action,
    ): JsonResponse {
        $updated = $action->execute(
            $employee,
            $document,
            $request->validated(),
            $request->file('berkas'),
            $request,
        );

        return response()->json([
            'message' => 'Berkas berhasil diperbarui.',
            'document' => $this->payload($updated),
        ]);
    }

    /** Menghapus Berkas Lainnya standalone; lampiran riwayat ditolak oleh Action. */
    public function destroyBerkasLainnya(
        DeleteBerkasLainnyaRequest $request,
        Employee $employee,
        Document $document,
        DeleteBerkasLainnyaAction $action,
    ): JsonResponse {
        $documentId = $document->id;
        $action->execute($employee, $document, $request);

        return response()->json([
            'message' => 'Berkas berhasil dihapus.',
            'document_id' => $documentId,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(Document $document): array
    {
        return [
            'id' => $document->id,
            'nama_dokumen' => $document->nama_dokumen,
            'jenis_dokumen' => $document->jenis_dokumen,
            'kategori_label' => DocumentCategory::label($document->jenis_dokumen),
            'nomor_dokumen' => $document->nomor_dokumen,
            'tanggal_dokumen' => $document->tanggal_dokumen?->format('d-m-Y'),
            'tanggal_input' => $document->tanggal_dokumen?->format('Y-m-d'),
            'file_size' => $document->fileSizeLabel(),
            'file_tersedia' => $document->fileExists(),
            'keterangan' => $document->keterangan,
            'detail_url' => route('dokumen.show', $document->id),
            'download_url' => route('dokumen.download', $document->id),
            'can_mutate' => true,
        ];
    }
}
