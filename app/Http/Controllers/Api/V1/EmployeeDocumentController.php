<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\DeleteDocumentAction;
use App\Actions\Documents\StoreBerkasLainnyaAction;
use App\Actions\Documents\StoreBerkasSkAction;
use App\Actions\Documents\StoreDocumentAction;
use App\Actions\Documents\UpdateDocumentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\CheckEmployeeDocumentImpactRequest;
use App\Http\Requests\Documents\DeleteEmployeeDocumentRequest;
use App\Http\Requests\Documents\ReplaceBerkasSkRequest;
use App\Http\Requests\Documents\StoreBerkasLainnyaRequest;
use App\Http\Requests\Documents\StoreBerkasSkRequest;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Requests\Documents\UpdateDocumentRequest;
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
     * Menambah SK pangkat/jabatan/KGB sebagai riwayat baru, atau mengganti SK pengangkatan.
     */
    public function storeBerkasSk(
        StoreBerkasSkRequest $request,
        Employee $employee,
        StoreBerkasSkAction $action,
    ): JsonResponse {
        $document = $action->execute($employee, $request->validated(), $request);

        return response()->json([
            'message' => $document->jenis_dokumen === 'sk_pengangkatan'
                ? 'SK Pengangkatan berhasil diganti.'
                : 'SK berhasil ditambahkan ke riwayat.',
            'document' => $this->documentPayload($document),
        ], 201);
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
            'document' => $this->documentPayload($document),
        ], 201);
    }

    /**
     * Mengunggah berkas dari tab Dokumen & SK halaman detail pegawai.
     *
     * Riwayat bersifat append-only sehingga endpoint ini tidak menimpa referensi
     * file pada riwayat yang sudah terisi: berkas disimpan sebagai arsip dokumen,
     * dan file_sk pada riwayat terbaru hanya dilengkapi bila masih kosong. Untuk
     * mengganti SK yang salah/rusak gunakan penambahan riwayat baru pada endpoint
     * berkas-sk. Pegawai diambil dari route sehingga payload dari klien tidak
     * bisa lintas pegawai.
     */
    public function storeDokumen(
        StoreDocumentRequest $request,
        Employee $employee,
        StoreDocumentAction $action,
    ): JsonResponse {
        $payload = [
            ...$request->safe()->except('pegawai_id'),
            'pegawai_id' => $employee->id,
        ];

        $document = $action->execute($payload, $request->file('berkas'));

        return response()->json([
            'message' => 'Dokumen berhasil disimpan.',
            'document' => $this->documentPayload($document),
        ], 201);
    }

    /**
     * Mengubah metadata (dan opsional mengganti file) berkas non-SK milik pegawai.
     *
     * Dokumen SK diproteksi: metadata resminya milik riwayat, sehingga hanya
     * berkas lainnya (ijazah, ktp_kk, lainnya) yang boleh diedit dari tab.
     */
    public function updateDokumen(
        UpdateDocumentRequest $request,
        Employee $employee,
        Document $document,
        UpdateDocumentAction $action,
    ): JsonResponse {
        $this->ensureOwned($employee, $document);
        $this->ensureEditable($document);

        $document = $action->execute($document, $request->safe()->except('berkas'), $request->file('berkas'));

        return response()->json([
            'message' => 'Dokumen berhasil diperbarui.',
            'document' => $this->documentPayload($document),
        ]);
    }

    /**
     * Mengganti berkas fisik SK yang sudah ada tanpa mengubah metadata resminya.
     *
     * Metadata SK (nomor, tanggal, kategori) bersifat read-only karena milik
     * riwayat; hanya isi berkas yang boleh diganti. Berkas lama dihapus permanen
     * setelah file baru tersimpan dan riwayat ter-sinkronisasi.
     */
    public function replaceBerkasSk(
        ReplaceBerkasSkRequest $request,
        Employee $employee,
        Document $document,
        UpdateDocumentAction $action,
    ): JsonResponse {
        $this->ensureOwned($employee, $document);

        abort_unless(
            DocumentCategory::isTabSk($document->jenis_dokumen),
            404,
            'Hanya berkas SK yang dapat diganti melalui jalur ini.',
        );

        // Salin metadata eksisting sebagai payload read-only agar hanya file_path
        // yang berubah; kategori tetap sama (tidak ada reklasifikasi).
        $payload = [
            'kategori_dokumen' => $document->jenis_dokumen,
            'nama_dokumen' => $document->nama_dokumen,
            'nomor_dokumen' => $document->nomor_dokumen,
            'tanggal_terbit' => $document->tanggal_dokumen?->format('Y-m-d'),
            'deskripsi' => $document->keterangan,
        ];

        $document = $action->execute($document, $payload, $request->file('berkas'));

        return response()->json([
            'message' => 'Berkas SK berhasil diganti; berkas lama dihapus.',
            'document' => $this->documentPayload($document),
        ]);
    }

    /**
     * Cek dampak hapus berkas pegawai sebelum Super Admin mengonfirmasi.
     */
    public function checkImpact(
        CheckEmployeeDocumentImpactRequest $request,
        Employee $employee,
        Document $document,
        DeleteDocumentAction $action,
    ): JsonResponse {
        $this->ensureOwned($employee, $document);

        return response()->json([
            'nomor_dokumen' => $document->nomor_dokumen,
            'nama_dokumen' => $document->nama_dokumen,
            'deletable' => DocumentCategory::isDeletable($document->jenis_dokumen),
            ...$action->checkImpact($document),
        ]);
    }

    /**
     * Hapus berkas non-SK. Hanya Super Admin, dan hanya kategori yang isDeletable.
     */
    public function destroy(
        DeleteEmployeeDocumentRequest $request,
        Employee $employee,
        Document $document,
        DeleteDocumentAction $action,
    ): JsonResponse {
        $this->ensureOwned($employee, $document);
        $action->execute($document);

        return response()->json(['message' => 'Berkas berhasil dihapus.']);
    }

    private function ensureOwned(Employee $employee, Document $document): void
    {
        abort_unless($document->employee_id === $employee->id, 404);
    }

    private function ensureEditable(Document $document): void
    {
        abort_unless(DocumentCategory::isDeletable($document->jenis_dokumen), 403, 'Dokumen SK tidak dapat diedit; metadata resminya dikelola oleh riwayat.');
    }

    /**
     * @return array<string, mixed>
     */
    private function documentPayload(Document $document): array
    {
        return [
            'id' => $document->id,
            'nama_dokumen' => $document->nama_dokumen,
            'jenis_dokumen' => $document->jenis_dokumen,
            'kategori_label' => DocumentCategory::label($document->jenis_dokumen),
            'nomor_dokumen' => $document->nomor_dokumen,
            'tanggal_dokumen' => $document->tanggal_dokumen?->format('d-m-Y'),
            'file_size' => $document->fileSizeLabel(),
            'file_path' => $document->file_path,
            'keterangan' => $document->keterangan,
            'is_deletable' => DocumentCategory::isDeletable($document->jenis_dokumen),
            'detail_url' => route('dokumen.show', $document->id),
            'download_url' => route('dokumen.download', $document->id),
        ];
    }
}
