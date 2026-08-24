<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\BuildsDocumentAuditPayload;
use App\Actions\Documents\Concerns\InteractsWithEmployeeDocumentStorage;
use App\Models\Document;
use App\Models\Employee;
use App\Services\AuditService;
use App\Services\TransactionSideEffectManager;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StoreBerkasLainnyaAction
{
    use BuildsDocumentAuditPayload;
    use InteractsWithEmployeeDocumentStorage;

    public function __construct(private readonly TransactionSideEffectManager $sideEffects) {}

    /**
     * Menyimpan berkas lainnya (KTP/KK, ijazah, lainnya) dari modal profil pegawai.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Employee $employee, array $data, UploadedFile $file, ?Request $request = null): Document
    {
        $category = (string) $data['kategori_dokumen'];
        // Guard penyimpanan: disk throw => false bisa mengembalikan false saat penuh/
        // read-only. Ditolak di sini sebelum transaksi DB agar tidak ada metadata
        // dengan path invalid atau file yatim.
        $filePath = $this->storeValidatedFile(
            $file,
            $employee->id.'/'.$category,
            $this->buildEmployeeDocumentFilename($employee->id, $category, $file),
        );

        // Transaksi middleware dapat membungkus transaksi Action. Kompensasi ini
        // memastikan file ikut dibersihkan bila transaksi request terluar rollback.
        $this->sideEffects->afterRollback(function () use ($filePath): void {
            Storage::disk(Document::STORAGE_DISK)->delete($filePath);
        });

        try {
            return DB::transaction(function () use ($employee, $data, $filePath, $request): Document {
                $document = Document::create([
                    'employee_id' => $employee->id,
                    'jenis_dokumen' => $data['kategori_dokumen'],
                    'nama_dokumen' => $data['nama_dokumen'],
                    'nomor_dokumen' => $data['nomor_dokumen'] ?? null,
                    'tanggal_dokumen' => $data['tanggal_terbit'] ?? null,
                    'file_path' => $filePath,
                    'keterangan' => $data['keterangan'] ?? null,
                ]);

                // Audit fail-closed: jika penulisan audit gagal, transaksi di-rollback
                // sehingga mutasi tidak pernah berhasil tanpa jejak audit.
                AuditService::logOrFail(
                    'CREATE',
                    'Document',
                    $document->id,
                    null,
                    $this->auditPayload($document),
                    $request,
                );

                return $document;
            });
        } catch (Throwable $exception) {
            // Kegagalan transaksi/audit tidak boleh meninggalkan file tanpa record dokumen.
            Storage::disk(Document::STORAGE_DISK)->delete($filePath);

            throw $exception;
        }
    }
}
