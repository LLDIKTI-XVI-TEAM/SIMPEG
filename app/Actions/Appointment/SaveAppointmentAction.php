<?php

namespace App\Actions\Appointment;

use App\Models\Appointment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\RefJenisPegawai;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Cuti\AnnualLeaveBusinessClock;
use App\Services\Cuti\AnnualLeaveCeilingService;
use App\Services\Cuti\EmploymentStartDateResolver;
use App\Services\Cuti\LeaveBalanceRecalculationService;
use App\Services\EmployeeFileStorageService;
use App\Services\Employees\TmtCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveAppointmentAction
{
    public function __construct(
        private readonly EmployeeFileStorageService $files,
        private readonly EmploymentStartDateResolver $employmentStartDate,
        private readonly LeaveBalanceRecalculationService $leaveBalanceRecalculation,
        private readonly AnnualLeaveCeilingService $annualLeaveCeiling,
        private readonly AnnualLeaveBusinessClock $annualLeaveBusinessClock,
        private readonly TmtCalculatorService $tmtCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(
        Employee $employee,
        array $data,
        ?UploadedFile $file = null,
        ?Request $request = null,
    ): Appointment {
        $storedPath = null;
        if ($file instanceof UploadedFile) {
            $user = $request?->user() ?? auth()->user();
            $canCreateDoc = $user === null || $user->hasPermission('dokumen_sk.create');
            if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
                $canCreateDoc = true;
            }
            if (! $canCreateDoc) {
                $file = null;
            } else {
                $storedPath = $this->files->storeSk($file);
                $data['file_sk'] = $storedPath;
            }
        }

        $replacedPath = null;

        try {
            $appointment = DB::transaction(function () use ($employee, $data, $request, &$replacedPath): Appointment {
                $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
                $effectiveTmtBefore = $this->employmentStartDate->earliestAppointmentTmt($employee);
                $annualLeaveCeilingsBefore = $this->annualLeaveCeilingSnapshot($employee);

                // Selector kanonis deterministik "pengangkatan pertama": TMT paling awal,
                // lalu id paling kecil. Tanpa ordering, record yang diubah bisa berbeda
                // tergantung hasil DB bila pegawai memiliki >1 Appointment.
                $appointment = Appointment::query()
                    ->where('employee_id', $employee->id)
                    ->orderBy('tmt_pengangkatan')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
                if ($appointment) {
                    $oldValues = $appointment->toArray();
                    $replacedPath = $appointment->file_sk;
                    $appointment->update($data);
                    AuditService::log('UPDATE', 'Appointment', $appointment->id, $oldValues, $appointment->toArray(), $request);
                } else {
                    $appointment = $employee->appointment()->create($data);
                    AuditService::log('CREATE', 'Appointment', $appointment->id, null, $appointment->toArray(), $request);
                }

                $jenisPegawai = RefJenisPegawai::query()
                    ->whereRaw('UPPER(nama) = ?', [strtoupper($appointment->jenis_pengangkatan)])
                    ->first();
                if ($jenisPegawai === null) {
                    throw ValidationException::withMessages([
                        'jenis_pengangkatan' => 'Referensi jenis pegawai untuk pengangkatan belum tersedia.',
                    ]);
                }
                $employee->update(['jenis_pegawai_id' => $jenisPegawai->id]);

                if ($appointment->file_sk) {
                    $employee->documents()->updateOrCreate([
                        'jenis_dokumen' => 'sk_pengangkatan',
                    ], [
                        'nama_dokumen' => 'SK Pengangkatan '.($appointment->jenis_pengangkatan ?: ''),
                        'nomor_dokumen' => $appointment->no_sk,
                        'tanggal_dokumen' => $appointment->tanggal_sk,
                        'file_path' => $appointment->file_sk,
                        'keterangan' => 'Dokumen SK pengangkatan pertama pegawai.',
                    ]);
                }

                // Endpoint JSON ini juga mengubah syarat masa kerja. Samakan efek domainnya
                // dengan UpdateEmployeeAction: saldo tahunan diproyeksikan ulang dan milestone EWS disegarkan.
                $employee->unsetRelation('appointments')->unsetRelation('jenisPegawai');
                $effectiveTmtAfter = $this->employmentStartDate->earliestAppointmentTmt($employee);
                $annualLeaveCeilingsAfter = $this->annualLeaveCeilingSnapshot($employee);
                if ($this->dateChanged($effectiveTmtBefore, $effectiveTmtAfter)
                    || $annualLeaveCeilingsBefore !== $annualLeaveCeilingsAfter) {
                    $actor = $request?->user();
                    if (! $actor instanceof User) {
                        throw ValidationException::withMessages([
                            'actor' => 'Aktor perubahan pengangkatan tidak dapat diverifikasi.',
                        ]);
                    }

                    $this->leaveBalanceRecalculation->recalculateForEmploymentTermsChange(
                        $employee,
                        $this->annualLeaveReplayStartYear($employee),
                        $effectiveTmtBefore,
                        $actor,
                        'Proyeksi saldo cuti tahunan direkalkulasi karena data pengangkatan berubah.',
                        $request,
                    );
                }

                $this->tmtCalculator->syncForEmployee($employee);

                return $appointment->fresh() ?? $appointment;
            });
        } catch (\Throwable $exception) {
            $this->files->deleteEmployeeDocumentFile($storedPath);

            throw $exception;
        }

        if ($storedPath !== null && $replacedPath !== $storedPath) {
            $this->files->deleteReplacedEmployeeDocumentFile($replacedPath);
        }

        return $appointment;
    }

    /** @return array<int, int> */
    private function annualLeaveCeilingSnapshot(Employee $employee): array
    {
        $employee->loadMissing('jenisPegawai');
        $currentYear = $this->annualLeaveBusinessClock->currentYear();
        $snapshot = [];

        for ($year = max(1900, $currentYear - 2); $year <= $currentYear; $year++) {
            $snapshot[$year] = $this->annualLeaveCeiling->maximumFor($employee, $year, 0, 0);
        }

        return $snapshot;
    }

    private function annualLeaveReplayStartYear(Employee $employee): int
    {
        $currentYear = $this->annualLeaveBusinessClock->currentYear();
        $materialStart = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('tahun', [$currentYear - 2, $currentYear])
            ->min('tahun');

        return $materialStart === null ? $currentYear : (int) $materialStart;
    }

    private function dateChanged(?Carbon $before, ?Carbon $after): bool
    {
        return $before?->toDateString() !== $after?->toDateString();
    }
}
