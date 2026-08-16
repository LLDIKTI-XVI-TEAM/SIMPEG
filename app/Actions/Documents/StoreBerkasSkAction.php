<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\Employee;
use App\Services\EmployeeHistoryService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class StoreBerkasSkAction
{
    public function __construct(
        private readonly EmployeeHistoryService $histories,
        private readonly ReplaceAppointmentSkAction $replaceAppointment,
    ) {}

    /**
     * Menyimpan SK dari tab Dokumen SK: append riwayat atau replace pengangkatan.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Employee $employee, array $data, Request $request): Document
    {
        return match ($data['kategori_dokumen']) {
            'sk_pangkat' => $this->documentForPath(
                $employee,
                'sk_pangkat',
                $this->histories->createRankHistory($employee, $data, $request)->file_sk,
            ),
            'sk_jabatan' => $this->documentForPath(
                $employee,
                'sk_jabatan',
                $this->histories->createPositionHistory($employee, $data, $request)->file_sk,
            ),
            'sk_kgb' => $this->documentForPath(
                $employee,
                'sk_kgb',
                $this->histories->createKgbHistory($employee, $data, $request)->file_sk,
            ),
            'sk_pengangkatan' => $this->replaceAppointment->execute($employee, $data, $request),
            default => throw new InvalidArgumentException('Kategori SK tidak dikenali.'),
        };
    }

    private function documentForPath(Employee $employee, string $category, ?string $filePath): Document
    {
        $query = $employee->documents()->where('jenis_dokumen', $category);

        if (filled($filePath)) {
            $document = (clone $query)
                ->where('file_path', $filePath)
                ->latest('created_at')
                ->first();

            if ($document !== null) {
                return $document;
            }
        }

        return $query->latest('created_at')->firstOrFail();
    }
}
