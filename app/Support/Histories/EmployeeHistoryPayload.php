<?php

namespace App\Support\Histories;

use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\SalaryHistory;
use App\Services\Employees\EmployeeHistoryAttachmentService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class EmployeeHistoryPayload
{
    public function __construct(private readonly EmployeeHistoryAttachmentService $attachments) {}

    /** @param iterable<int, mixed> $paths */
    public function primeAttachmentReferences(iterable $paths): void
    {
        $this->attachments->primeDocumentReferences($paths);
    }

    /**
     * Mempertahankan kontrak riwayat pangkat dengan tanggal kalender yang deterministik.
     *
     * @return array<string, mixed>
     */
    public function rank(RankHistory $history, ?Employee $employee = null): array
    {
        return $this->withDownloadUrl($this->withDateOnlyFields($history->toArray(), [
            'tanggal_sk' => $history->tanggal_sk,
            'tmt_pangkat' => $history->tmt_pangkat,
        ]), $employee, 'rank', $history);
    }

    /**
     * Mempertahankan kontrak riwayat jabatan dengan tanggal kalender yang deterministik.
     *
     * @return array<string, mixed>
     */
    public function position(PositionHistory $history, ?Employee $employee = null): array
    {
        return $this->withDownloadUrl($this->withDateOnlyFields($history->toArray(), [
            'tanggal_sk' => $history->tanggal_sk,
            'tmt_jabatan' => $history->tmt_jabatan,
        ]), $employee, 'position', $history);
    }

    /**
     * Mempertahankan kontrak riwayat KGB dengan tanggal kalender yang deterministik.
     *
     * @return array<string, mixed>
     */
    public function kgb(SalaryHistory $history, ?Employee $employee = null): array
    {
        return $this->withDownloadUrl($this->withDateOnlyFields($history->toArray(), [
            'tanggal_sk' => $history->tanggal_sk,
            'tmt_kgb' => $history->tmt_kgb,
        ]), $employee, 'salary', $history);
    }

    /**
     * Field tanggal kalender tidak boleh mengikuti serialisasi timestamp UTC bawaan Eloquent.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, CarbonInterface|null>  $dates
     * @return array<string, mixed>
     */
    private function withDateOnlyFields(array $payload, array $dates): array
    {
        foreach ($dates as $field => $date) {
            $payload[$field] = $date?->format('Y-m-d');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function withDownloadUrl(array $payload, ?Employee $employee, string $type, Model $history): array
    {
        $payload['download_url'] = $employee === null
            ? null
            : $this->attachments->downloadUrl($employee, $type, $history, 'pegawai.history-attachments.download');

        return $payload;
    }
}
