<?php

namespace App\Services\Cuti;

use App\Data\Cuti\ManualExternalApprovalStepData;
use App\Models\Employee;
use App\Models\LeaveUsageExternalApprovalStep;
use App\Models\LeaveUsageRecord;
use App\Support\Cuti\CutiInstitution;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ManualExternalApprovalChainService
{
    private const ALLOWED_KEYS = [
        'step_type',
        'approver_source',
        'approver_employee_id',
        'approver_name',
        'approver_position',
        'approver_institution',
        'acted_on',
        'decision_note',
    ];

    public function __construct(
        private readonly AnnualLeaveBusinessClock $businessClock,
    ) {}

    /**
     * Mengembalikan error berindeks yang sama untuk FormRequest dan pemanggilan Action langsung.
     *
     * @return array<string, list<string>>
     */
    public function violations(mixed $steps): array
    {
        $errors = [];

        if (! is_array($steps) || ! array_is_list($steps)) {
            return ['approval_steps' => ['Riwayat persetujuan wajib berupa daftar tahap terurut.']];
        }

        $count = count($steps);
        if ($count < 2 || $count > 10) {
            $this->addError($errors, 'approval_steps', 'Riwayat persetujuan wajib berisi 2 sampai 10 tahap.');
        }

        $types = [];
        $internalIds = [];
        $previousActedOn = null;
        // Keputusan manual memakai tanggal bisnis WITA meski timezone proses aplikasi berbeda.
        $businessDate = $this->businessClock->today()->toDateString();

        foreach ($steps as $index => $step) {
            $base = "approval_steps.{$index}";
            if (! is_array($step)) {
                $this->addError($errors, $base, 'Setiap tahap persetujuan wajib berupa objek.');

                continue;
            }

            foreach (array_diff(array_keys($step), self::ALLOWED_KEYS) as $unknown) {
                $this->addError($errors, "{$base}.{$unknown}", 'Field ini tidak boleh dikirim oleh client.');
            }

            $type = $step['step_type'] ?? null;
            if (! is_string($type) || ! in_array($type, [
                LeaveUsageExternalApprovalStep::TYPE_VERIFIER,
                LeaveUsageExternalApprovalStep::TYPE_KEPALA_BAGIAN,
                LeaveUsageExternalApprovalStep::TYPE_PYBMC,
            ], true)) {
                $this->addError($errors, "{$base}.step_type", 'Jenis tahap persetujuan tidak valid.');
            } else {
                $types[$index] = $type;
            }

            $source = $step['approver_source'] ?? null;
            if (! is_string($source) || ! in_array($source, [
                LeaveUsageExternalApprovalStep::SOURCE_SIMPEG_EMPLOYEE,
                LeaveUsageExternalApprovalStep::SOURCE_EXTERNAL_OFFICIAL,
            ], true)) {
                $this->addError($errors, "{$base}.approver_source", 'Sumber identitas approver tidak valid.');
            } elseif ($source === LeaveUsageExternalApprovalStep::SOURCE_SIMPEG_EMPLOYEE) {
                $employeeId = $step['approver_employee_id'] ?? null;
                if (! is_string($employeeId) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $employeeId)) {
                    $this->addError($errors, "{$base}.approver_employee_id", 'Pegawai approver wajib dipilih.');
                } else {
                    $internalIds[$index] = $employeeId;
                }

                foreach (['approver_name', 'approver_position', 'approver_institution'] as $field) {
                    if ($this->normalizeNullable($step[$field] ?? null) !== null) {
                        $this->addError($errors, "{$base}.{$field}", 'Identitas pegawai internal diturunkan server.');
                    }
                }
            } elseif ($source === LeaveUsageExternalApprovalStep::SOURCE_EXTERNAL_OFFICIAL) {
                if ($this->normalizeNullable($step['approver_employee_id'] ?? null) !== null) {
                    $this->addError($errors, "{$base}.approver_employee_id", 'Pejabat external tidak boleh terkait UUID pegawai.');
                }

                foreach ([
                    'approver_name' => 255,
                    'approver_position' => 255,
                    'approver_institution' => 255,
                ] as $field => $max) {
                    $value = $this->normalizeNullable($step[$field] ?? null);
                    if ($value === null) {
                        $this->addError($errors, "{$base}.{$field}", 'Identitas pejabat external wajib diisi.');
                    } elseif (mb_strlen($value) > $max) {
                        $this->addError($errors, "{$base}.{$field}", "Field ini maksimal {$max} karakter.");
                    }
                }
            }

            $actedOn = $step['acted_on'] ?? null;
            $date = is_string($actedOn) ? DateTimeImmutable::createFromFormat('!Y-m-d', $actedOn) : false;
            if ($date === false || $date->format('Y-m-d') !== $actedOn) {
                $this->addError($errors, "{$base}.acted_on", 'Tanggal tindakan wajib berformat Y-m-d.');
            } elseif ($actedOn > $businessDate) {
                $this->addError($errors, "{$base}.acted_on", 'Tanggal tindakan tidak boleh berada di masa depan.');
            } elseif ($previousActedOn !== null && $actedOn < $previousActedOn) {
                $this->addError($errors, "{$base}.acted_on", 'Tanggal tindakan tidak boleh lebih awal dari tahap sebelumnya.');
            } else {
                $previousActedOn = $actedOn;
            }

            $note = $step['decision_note'] ?? null;
            if ($note !== null && ! is_string($note)) {
                $this->addError($errors, "{$base}.decision_note", 'Catatan tahap wajib berupa teks.');
            } elseif (is_string($note) && mb_strlen($note) > 2000) {
                $this->addError($errors, "{$base}.decision_note", 'Catatan tahap maksimal 2000 karakter.');
            }
        }

        $verifierCount = count(array_filter($types, fn (string $type): bool => $type === LeaveUsageExternalApprovalStep::TYPE_VERIFIER));
        $kepalaIndexes = array_keys($types, LeaveUsageExternalApprovalStep::TYPE_KEPALA_BAGIAN, true);
        $pybmcIndexes = array_keys($types, LeaveUsageExternalApprovalStep::TYPE_PYBMC, true);

        if ($verifierCount > 8) {
            $this->addError($errors, 'approval_steps', 'Jumlah Verifikator maksimal delapan tahap.');
        }
        if (count($kepalaIndexes) !== 1) {
            $this->addError($errors, 'approval_steps', 'Riwayat persetujuan wajib memiliki tepat satu Kepala Bagian.');
        }
        if (count($pybmcIndexes) !== 1) {
            $this->addError($errors, 'approval_steps', 'Riwayat persetujuan wajib memiliki tepat satu PYBMC.');
        }

        if (count($pybmcIndexes) === 1 && $pybmcIndexes[0] !== $count - 1) {
            $this->addError($errors, "approval_steps.{$pybmcIndexes[0]}.step_type", 'PYBMC wajib menjadi tahap terakhir.');
        }
        if (count($kepalaIndexes) === 1) {
            foreach ($types as $index => $type) {
                if ($type === LeaveUsageExternalApprovalStep::TYPE_VERIFIER && $index > $kepalaIndexes[0]) {
                    $this->addError($errors, "approval_steps.{$index}.step_type", 'Seluruh Verifikator wajib berada sebelum Kepala Bagian.');
                }
            }
        }

        if ($internalIds !== []) {
            $found = Employee::query()
                ->whereIn('id', array_values(array_unique($internalIds)))
                ->pluck('id')
                ->all();
            foreach ($internalIds as $index => $employeeId) {
                if (! in_array($employeeId, $found, true)) {
                    $this->addError($errors, "approval_steps.{$index}.approver_employee_id", 'Pegawai approver tidak ditemukan.');
                }
            }
        }

        return $errors;
    }

    /**
     * Menurunkan urutan dari posisi array dan hasil dari jenis tahap setelah satu validator struktural lulus.
     *
     * @return list<ManualExternalApprovalStepData>
     */
    public function normalize(mixed $steps): array
    {
        $violations = $this->violations($steps);
        if ($violations !== []) {
            throw ValidationException::withMessages($violations);
        }

        /** @var list<array<string, mixed>> $steps */
        return collect($steps)
            ->values()
            ->map(fn (array $step, int $index): ManualExternalApprovalStepData => new ManualExternalApprovalStepData(
                order: $index + 1,
                stepType: (string) $step['step_type'],
                approverSource: (string) $step['approver_source'],
                approverEmployeeId: $this->normalizeNullable($step['approver_employee_id'] ?? null),
                externalName: $this->normalizeNullable($step['approver_name'] ?? null),
                externalPosition: $this->normalizeNullable($step['approver_position'] ?? null),
                externalInstitution: $this->normalizeNullable($step['approver_institution'] ?? null),
                actedOn: (string) $step['acted_on'],
                decisionNote: $this->normalizeNullable($step['decision_note'] ?? null),
            ))
            ->all();
    }

    /**
     * Menyimpan identitas internal dalam satu query bounded agar perubahan profil berikutnya tidak mengubah histori.
     *
     * @param  list<ManualExternalApprovalStepData>  $steps
     * @param  Collection<int, LeaveUsageExternalApprovalStep>|null  $previousSteps
     * @return Collection<int, LeaveUsageExternalApprovalStep>
     */
    public function storeSnapshot(LeaveUsageRecord $record, array $steps, ?Collection $previousSteps = null): Collection
    {
        $internalIds = collect($steps)
            ->pluck('approverEmployeeId')
            ->filter(fn (mixed $id): bool => is_string($id))
            ->unique()
            ->values()
            ->all();
        $employees = Employee::query()
            ->whereIn('id', $internalIds)
            ->get(['id', 'nama_lengkap', 'nip', 'jabatan_terakhir'])
            ->keyBy('id');
        $preservedInternalSnapshots = ($previousSteps ?? collect())
            ->filter(fn (LeaveUsageExternalApprovalStep $step): bool => $step->approver_source === LeaveUsageExternalApprovalStep::SOURCE_SIMPEG_EMPLOYEE)
            ->values();
        $stored = collect();

        foreach ($steps as $step) {
            $preserved = $this->takePreservedInternalSnapshot($preservedInternalSnapshots, $step);
            $employee = $step->approverEmployeeId === null ? null : $employees->get($step->approverEmployeeId);
            if ($step->approverSource === LeaveUsageExternalApprovalStep::SOURCE_SIMPEG_EMPLOYEE
                && $preserved === null
                && (! $employee instanceof Employee || $this->normalizeNullable($employee->nama_lengkap) === null)) {
                throw ValidationException::withMessages([
                    'approval_steps.'.($step->order - 1).'.approver_employee_id' => 'Identitas pegawai approver tidak lengkap.',
                ]);
            }

            $stored->push(LeaveUsageExternalApprovalStep::query()->create([
                'leave_usage_record_id' => $record->id,
                'step_order' => $step->order,
                'step_type' => $step->stepType,
                'approver_source' => $step->approverSource,
                'approver_employee_id' => $preserved instanceof LeaveUsageExternalApprovalStep
                    ? $preserved->approver_employee_id
                    : $employee?->id,
                'approver_name_snapshot' => $preserved instanceof LeaveUsageExternalApprovalStep
                    ? $preserved->approver_name_snapshot
                    : ($employee instanceof Employee
                        ? $this->normalizeNullable($employee->nama_lengkap)
                        : $step->externalName),
                'approver_nip_snapshot' => $preserved instanceof LeaveUsageExternalApprovalStep
                    ? $preserved->approver_nip_snapshot
                    : ($employee instanceof Employee
                        ? $this->normalizeNullable($employee->nip)
                        : null),
                'approver_position_snapshot' => $preserved instanceof LeaveUsageExternalApprovalStep
                    ? $preserved->approver_position_snapshot
                    : ($employee instanceof Employee
                        ? $this->normalizeNullable($employee->jabatan_terakhir)
                        : $step->externalPosition),
                'approver_institution_snapshot' => $preserved instanceof LeaveUsageExternalApprovalStep
                    ? $preserved->approver_institution_snapshot
                    : ($employee instanceof Employee
                        ? CutiInstitution::NAME
                        : $step->externalInstitution),
                'acted_on' => $step->actedOn,
                'result_code' => $step->resultCode(),
                'decision_note' => $step->decisionNote,
            ]));
        }

        return $stored;
    }

    /**
     * Mengambil tepat satu snapshot server lama untuk UUID internal yang sama.
     * Pencocokan tidak memakai indeks agar tambah/hapus tahap tidak menggeser identitas historis.
     *
     * @param  Collection<int, LeaveUsageExternalApprovalStep>  $snapshots
     */
    private function takePreservedInternalSnapshot(
        Collection $snapshots,
        ManualExternalApprovalStepData $step,
    ): ?LeaveUsageExternalApprovalStep {
        if ($step->approverSource !== LeaveUsageExternalApprovalStep::SOURCE_SIMPEG_EMPLOYEE
            || $step->approverEmployeeId === null) {
            return null;
        }

        $key = $snapshots->search(
            fn (LeaveUsageExternalApprovalStep $snapshot): bool => $snapshot->approver_employee_id === $step->approverEmployeeId,
        );
        if ($key === false) {
            return null;
        }

        /** @var LeaveUsageExternalApprovalStep $snapshot */
        $snapshot = $snapshots->get($key);
        $snapshots->forget($key);

        return $snapshot;
    }

    /** @param Collection<int, LeaveUsageExternalApprovalStep> $steps */
    public function auditPayload(Collection $steps): array
    {
        return $steps->map(fn (LeaveUsageExternalApprovalStep $step): array => [
            'step_order' => $step->step_order,
            'step_type' => $step->step_type,
            'approver_source' => $step->approver_source,
            'approver_employee_id' => $step->approver_employee_id,
            'approver_name' => $step->approver_name_snapshot,
            'approver_nip' => $step->approver_nip_snapshot,
            'approver_position' => $step->approver_position_snapshot,
            'approver_institution' => $step->approver_institution_snapshot,
            'acted_on' => $step->acted_on->toDateString(),
            'result_code' => $step->result_code,
            'decision_note' => $step->decision_note,
        ])->values()->all();
    }

    private function normalizeNullable(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = preg_replace('/[\p{Z}\s]+/u', ' ', $value);
        if ($normalized === null) {
            return null;
        }

        $normalized = trim($normalized);

        return $normalized === '' ? null : $normalized;
    }

    /** @param array<string, list<string>> $errors */
    private function addError(array &$errors, string $path, string $message): void
    {
        $errors[$path] ??= [];
        $errors[$path][] = $message;
    }
}
