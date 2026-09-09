<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employees\AssignSupervisorAction;
use App\Actions\Employees\CreateEmployeeAction;
use App\Actions\Employees\DeactivateEmployeeAction;
use App\Actions\Employees\ListEmployeesAction;
use App\Actions\Employees\RestoreEmployeeAction;
use App\Actions\Employees\ShowEmployeeAction;
use App\Actions\Employees\ShowEmployeeDocumentStatusAction;
use App\Actions\Employees\ShowMyProfileAction;
use App\Actions\Employees\UpdateEmployeeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\AssignSupervisorRequest;
use App\Http\Requests\Employee\DeactivateEmployeeRequest;
use App\Http\Requests\Employee\ListEmployeesRequest;
use App\Http\Requests\Employee\RestoreEmployeeRequest;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(ListEmployeesRequest $request, ListEmployeesAction $action): JsonResponse
    {
        return response()->json([
            'message' => 'Daftar pegawai berhasil diambil.',
            'employees' => $action->execute($request->validated(), $request->user()),
        ]);
    }

    public function store(StoreEmployeeRequest $request, CreateEmployeeAction $action): JsonResponse|RedirectResponse
    {
        $employee = $action->execute($request->validated(), $request);
        $warnings = $action->warnings;

        if ($request->expectsJson()) {
            $message = 'Data pegawai berhasil ditambahkan.';
            if (! empty($warnings)) {
                $message .= ' Peringatan: '.implode(' ', $warnings);
            }
            $payload = [
                'message' => $message,
                'employee' => $employee,
            ];
            if (! empty($warnings)) {
                $payload['warnings'] = $warnings;
            }

            return response()->json($payload, 201);
        }

        return back()->with('success', 'Data pegawai berhasil ditambahkan.');
    }

    public function checkIdentity(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'string', 'in:nip,nik'],
            'value' => ['required', 'string'],
            'except_id' => ['nullable', 'uuid'],
        ]);

        // NIK dienkripsi AES-256 — WHERE nik = plaintext tidak pernah cocok.
        // Gunakan HMAC-SHA256 blind index (nik_hash) sebagai gantinya.
        if ($request->type === 'nik') {
            $hash = hash_hmac('sha256', trim($request->value), config('app.key'));
            // Seluruh pegawai (aktif maupun nonaktif) tercakup karena nonaktif
            // disimpan sebagai status, bukan dihapus dari tabel.
            $query = Employee::query()->where('nik_hash', $hash);
        } else {
            // NIP disimpan plaintext — perbandingan langsung berfungsi. Seluruh
            // pegawai (aktif maupun nonaktif) ikut dianggap sudah terpakai.
            $query = Employee::query()->where($request->type, $request->value);
        }

        if ($request->filled('except_id')) {
            $query->where('id', '!=', $request->except_id);
        }

        $exists = $query->exists();

        return response()->json([
            'is_unique' => ! $exists,
            'message' => $exists ? strtoupper($request->type).' sudah terdaftar.' : strtoupper($request->type).' tersedia.',
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, UpdateEmployeeAction $action): JsonResponse|RedirectResponse
    {
        $employee = $action->execute($employee, $request->validated(), $request);
        $warnings = $action->warnings;

        if ($request->expectsJson()) {
            $message = 'Data pegawai berhasil diperbarui.';
            if (! empty($warnings)) {
                $message .= ' Peringatan: '.implode(' ', $warnings);
            }
            $payload = [
                'message' => $message,
                'employee' => $employee,
            ];
            if (! empty($warnings)) {
                $payload['warnings'] = $warnings;
            }

            return response()->json($payload);
        }

        return back()->with('success', 'Data pegawai berhasil diperbarui.');
    }

    public function show(Employee $employee, ShowEmployeeAction $action): JsonResponse
    {
        return response()->json([
            'message' => 'Detail pegawai berhasil diambil.',
            'employee' => $action->execute($employee),
        ]);
    }

    public function tableRow(Employee $employee): JsonResponse
    {
        $employee->load([
            'jenisPegawai:id,nama',
            'statusPegawai:id,nama',
            'rankHistories:id,employee_id,no_sk,tanggal_sk,tmt_pangkat,file_sk,is_latest,created_at',
            'positionHistories' => fn ($query) => $query
                ->select(['id', 'employee_id', 'no_sk', 'tanggal_sk', 'file_sk', 'is_latest', 'tmt_jabatan', 'jabatan_id', 'unit_kerja_id', 'created_at'])
                ->with(['jabatan:id,nama', 'unitKerja:id,nama'])
                ->orderByDesc('is_latest')
                ->orderByDesc('tmt_jabatan'),
            'salaryHistories:id,employee_id,no_sk,tanggal_sk,tmt_kgb,file_sk,is_latest,created_at',
            'appointments' => fn ($query) => $query
                ->select(['id', 'employee_id', 'jenis_pengangkatan', 'no_sk', 'tanggal_sk', 'file_sk', 'tmt_pengangkatan', 'created_at'])
                ->orderByDesc('tmt_pengangkatan')
                ->orderByDesc('created_at'),
            'documents:id,employee_id,jenis_dokumen,nama_dokumen,nomor_dokumen,tanggal_dokumen,file_path,keterangan,created_at',
        ]);

        return response()->json([
            'message' => 'Data baris pegawai berhasil diambil.',
            'employee' => app(ListEmployeesAction::class)->toTableRow($employee),
        ]);
    }

    public function documentStatus(Employee $employee, ShowEmployeeDocumentStatusAction $action): JsonResponse
    {
        return response()->json([
            'message' => 'Status dokumen pegawai berhasil diambil.',
            'employee' => [
                'id' => $employee->id,
                'nama_lengkap' => $employee->nama_lengkap,
                'nip' => $employee->nip,
            ],
            'document_status' => $action->execute($employee),
            'file_status_checked_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, private, max-age=0, must-revalidate');
    }

    public function destroy(Employee $employee, DeactivateEmployeeRequest $request, DeactivateEmployeeAction $action): JsonResponse
    {
        $result = $action->execute($employee, $request);

        return response()->json([
            'message' => $result->isScheduled()
                ? "Penonaktifan pegawai berhasil dijadwalkan untuk tanggal {$result->effectiveDate}."
                : 'Data pegawai berhasil dinonaktifkan.',
            'status_transition' => [
                'state' => $result->state,
                'effective_date' => $result->effectiveDate,
            ],
        ]);
    }

    public function restore(string $employee, RestoreEmployeeRequest $request, RestoreEmployeeAction $action): JsonResponse
    {
        $result = $action->execute(Employee::query()->findOrFail($employee), $request);

        return response()->json([
            'message' => $result->isScheduled()
                ? "Pengaktifan kembali pegawai berhasil dijadwalkan untuk tanggal {$result->effectiveDate}."
                : 'Data pegawai berhasil diaktifkan kembali.',
            'employee' => $this->employeeListPayload($result->employee),
            'status_transition' => [
                'state' => $result->state,
                'effective_date' => $result->effectiveDate,
            ],
        ]);
    }

    public function myProfile(Request $request, ShowMyProfileAction $action): JsonResponse
    {
        return response()->json([
            'message' => 'Detail profil pegawai berhasil diambil.',
            'employee' => $action->execute($request->user()?->employee),
        ]);
    }

    public function assignSupervisor(AssignSupervisorRequest $request, Employee $employee, AssignSupervisorAction $action): JsonResponse
    {
        $data = $request->validated();
        $updatedEmployee = $action->execute(
            $employee,
            $data['kepala_bagian_id'] ?? $data['supervisor_id'] ?? null,
            $data['effective_date'],
            $request,
        );

        return response()->json([
            'message' => 'Kepala bagian berhasil diperbarui.',
            'employee' => [
                'id' => $updatedEmployee->id,
                'nama_lengkap' => $updatedEmployee->nama_lengkap,
                'kepala_bagian' => $updatedEmployee->kepalaBagian ? [
                    'id' => $updatedEmployee->kepalaBagian->id,
                    'nama_lengkap' => $updatedEmployee->kepalaBagian->nama_lengkap,
                ] : null,

            ],
        ]);
    }

    /**
     * Payload ringkas untuk daftar nonaktif agar endpoint tidak mengekspos NIK/No KK dan data sensitif lain.
     *
     * @return array<string, mixed>
     */
    private function employeeListPayload(Employee $employee): array
    {
        $latestPosition = $employee->positionHistories->first();

        return [
            'id' => $employee->id,
            'nama_lengkap' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'jabatan_terakhir' => $employee->jabatan_terakhir,
            'golongan_terakhir' => $employee->golongan_terakhir,
            'jenis_pegawai' => $employee->jenisPegawai?->nama,
            'unit_kerja' => $latestPosition?->unitKerja?->nama,
            'status_aktif' => $employee->status_aktif,
        ];
    }
}
