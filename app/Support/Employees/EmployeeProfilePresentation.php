<?php

namespace App\Support\Employees;

use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EwsConfig;
use App\Support\Documents\LegacyStatusDocumentResolver;
use Illuminate\Support\Carbon;

final class EmployeeProfilePresentation
{
    /**
     * Menandai riwayat status legacy yang boleh menampilkan tautan unduhan privat.
     *
     * Dokumen wajib tetap milik pegawai pada detail ini, berkategori SK status, bernomor sama,
     * dan benar-benar tersedia di disk privat agar Blade tidak membangun fallback yang bocor.
     */
    public static function prepareStatusHistoryAttachments(Employee $employee): void
    {
        if (! $employee->relationLoaded('statusHistories') || ! $employee->relationLoaded('documents')) {
            return;
        }

        $employee->statusHistories->each(function (EmployeeStatusHistory $history) use ($employee): void {
            $canUseLegacyDocument = $history->file_sk === null
                && LegacyStatusDocumentResolver::resolve($employee->documents, $history) !== null;

            $history->setAttribute('has_legacy_status_document', $canUseLegacyDocument);
        });
    }

    /**
     * Menyusun status untuk kedua surface detail agar label dan warna tidak kembali berbeda.
     *
     * @return array{label: string, badge: string, dot: string, effectiveDate: Carbon|null}
     */
    public static function status(Employee $employee, ?EmployeeStatusHistory $latestStatusHistory = null): array
    {
        $statusLabel = $employee->statusPegawai?->nama ?? $employee->status_aktif ?? '-';
        $statusCode = mb_strtolower(trim((string) ($employee->statusPegawai?->kode ?? '')));
        $statusGroup = mb_strtolower(trim((string) ($employee->statusPegawai?->kelompok ?? '')));
        $normalizedLabel = mb_strtolower(trim($statusLabel));
        $isActive = $statusCode === 'aktif'
            || in_array($statusGroup, ['aktif', 'aktif/khusus'], true)
            || ($statusCode === '' && $normalizedLabel === 'aktif');
        $isPensiun = $statusCode === 'pensiun'
            || ($statusCode === '' && $normalizedLabel === 'pensiun');
        $statusStyle = match (true) {
            $isActive => ['badge' => 'bg-success/10 text-success', 'dot' => 'bg-success'],
            $isPensiun => ['badge' => 'bg-danger/10 text-danger', 'dot' => 'bg-danger'],
            default => ['badge' => 'bg-muted/10 text-muted', 'dot' => 'bg-muted'],
        };

        return [
            'label' => $statusLabel,
            'effectiveDate' => $employee->status_tanggal ?? $latestStatusHistory?->tanggal_efektif,
            ...$statusStyle,
        ];
    }

    /** Tanggal resmi diprioritaskan; data legacy tanpa snapshot memakai BUP global yang sama pada kedua surface. */
    public static function retirementDate(Employee $employee): ?Carbon
    {
        if ($employee->tanggal_pensiun !== null) {
            return $employee->tanggal_pensiun;
        }

        $requiredAgeYears = max(0, (int) EwsConfig::getVal('pensiun_required_age_years', 0));

        return $requiredAgeYears > 0 && $employee->tanggal_lahir
            ? $employee->tanggal_lahir->copy()->addYears($requiredAgeYears)
            : null;
    }
}
