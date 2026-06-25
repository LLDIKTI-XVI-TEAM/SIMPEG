<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeFamilyRequest;
use App\Http\Requests\UpdateEmployeeFamilyRequest;
use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

class EmployeeFamilyController extends Controller
{
    public function index(Employee $employee): JsonResponse
    {
        return response()->json([
            'employee_id' => $employee->id,
            'families' => $employee->families()
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (EmployeeFamily $family): array => $this->familyPayload($family))
                ->values(),
        ]);
    }

    public function store(StoreEmployeeFamilyRequest $request, Employee $employee): JsonResponse
    {
        $family = $employee->families()->create($request->validated());

        AuditService::log(
            'CREATE',
            'EmployeeFamily',
            $family->id,
            null,
            $this->auditPayload($family),
            $request,
        );

        return response()->json([
            'message' => 'Data keluarga berhasil ditambahkan.',
            'family' => $this->familyPayload($family),
        ], 201);
    }

    public function update(
        UpdateEmployeeFamilyRequest $request,
        Employee $employee,
        EmployeeFamily $family,
    ): JsonResponse {
        $this->abortIfFamilyOutsideEmployee($employee, $family);

        $oldValues = $this->auditPayload($family);

        $family->update($request->validated());
        $family->refresh();

        AuditService::log(
            'UPDATE',
            'EmployeeFamily',
            $family->id,
            $oldValues,
            $this->auditPayload($family),
            $request,
        );

        return response()->json([
            'message' => 'Data keluarga berhasil diperbarui.',
            'family' => $this->familyPayload($family),
        ]);
    }

    public function destroy(Employee $employee, EmployeeFamily $family): JsonResponse
    {
        $this->abortIfFamilyOutsideEmployee($employee, $family);

        $oldValues = $this->auditPayload($family);
        $family->delete();

        AuditService::log(
            'SOFT_DELETE',
            'EmployeeFamily',
            $family->id,
            $oldValues,
            null,
            request(),
        );

        return response()->json([
            'message' => 'Data keluarga berhasil dinonaktifkan.',
        ]);
    }

    private function abortIfFamilyOutsideEmployee(Employee $employee, EmployeeFamily $family): void
    {
        abort_unless($family->employee_id === $employee->id, 404);
    }

    /**
     * Membuka field keluarga yang aman dikembalikan ke admin; relasi pegawai tidak disertakan.
     */
    private function familyPayload(EmployeeFamily $family): array
    {
        return Arr::only($family->toArray(), [
            'id',
            'employee_id',
            'nama_anggota',
            'hubungan',
            'nik',
            'tempat_lahir',
            'tanggal_lahir',
            'jenis_kelamin',
            'status_tunjangan',
            'pekerjaan',
            'created_at',
            'updated_at',
        ]);
    }

    /**
     * NIK tidak dicatat di audit karena termasuk identitas keluarga yang sensitif.
     */
    private function auditPayload(EmployeeFamily $family): array
    {
        return Arr::except($this->familyPayload($family), ['nik']);
    }
}
