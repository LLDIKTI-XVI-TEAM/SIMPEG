<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreBerkasLainnyaRequest;
use App\Models\Document;
use App\Models\Employee;
use App\Services\AuditService;
use App\Services\TransactionSideEffectManager;
use App\Support\Documents\DocumentCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class EmployeeDocumentController extends Controller
{
    public function __construct(private readonly TransactionSideEffectManager $sideEffects) {}

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
    ): JsonResponse {
        $file = $request->file('berkas');
        $category = $request->input('kategori_dokumen');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $filename = $employee->id.'_'.$category.'_'.Str::uuid().'.'.$extension;
        $filePath = $file->storeAs($employee->id.'/'.$category, $filename, Document::STORAGE_DISK);
        $this->sideEffects->afterRollback(function () use ($filePath): void {
            Storage::disk(Document::STORAGE_DISK)->delete($filePath);
        });

        try {
            $document = DB::transaction(function () use ($employee, $request, $filePath, $category): Document {
                $doc = Document::create([
                    'employee_id' => $employee->id,
                    'jenis_dokumen' => $category,
                    'nama_dokumen' => $request->input('nama_dokumen'),
                    'nomor_dokumen' => $request->input('nomor_dokumen'),
                    'tanggal_dokumen' => $request->input('tanggal_terbit'),
                    'file_path' => $filePath,
                    'keterangan' => $request->input('keterangan'),
                ]);

                AuditService::log('CREATE', 'Document', $doc->id, null, $doc->toArray(), $request);

                return $doc;
            });
        } catch (Throwable $e) {
            // Rollback file fisik jika transaksi database gagal.
            Storage::disk(Document::STORAGE_DISK)->delete($filePath);
            throw $e;
        }

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
                'detail_url' => route('dokumen.show', $document->id),
                'download_url' => route('dokumen.download', $document->id),
            ],
        ], 201);
    }
}
