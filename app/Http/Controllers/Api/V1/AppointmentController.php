<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Appointment\SaveAppointmentAction;
use App\Actions\Appointment\UploadAppointmentSkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Appointment\SaveAppointmentRequest;
use App\Http\Requests\Appointment\UploadAppointmentSkRequest;
use App\Models\Appointment;
use App\Models\Employee;
use App\Support\Histories\EmployeeHistoryPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

class AppointmentController extends Controller
{
    public function show(Employee $employee, EmployeeHistoryPayload $payload): JsonResponse
    {
        $appointment = $employee->appointment;

        return response()->json([
            'employee_id' => $employee->id,
            'appointment' => $appointment ? $payload->appointment($appointment, $employee) : null,
        ]);
    }

    public function save(
        SaveAppointmentRequest $request,
        Employee $employee,
        SaveAppointmentAction $action,
        EmployeeHistoryPayload $payload,
    ): JsonResponse {
        $data = $request->safe()->except(['file_sk']);
        $file = $request->file('file_sk');
        $warning = null;
        $existingAppointment = Appointment::query()
            ->where('employee_id', $employee->id)
            ->orderBy('tmt_pengangkatan')
            ->orderBy('id')
            ->first();
        $documentPermission = $existingAppointment !== null && filled($existingAppointment->file_sk)
            ? 'dokumen_sk.update'
            : 'dokumen_sk.create';
        $canManageDocument = $request->user()?->hasPermission($documentPermission)
            ?? (app()->environment('local') && config('services.simpeg.disable_employee_api_auth'));
        if ($file instanceof UploadedFile && ! $canManageDocument) {
            $file = null;
            $warning = 'Data pengangkatan berhasil disimpan, tetapi berkas SK tidak diunggah karena Anda tidak memiliki permission '.$documentPermission.'.';
        }

        $appointment = $action->execute($employee, $data, $file, $request);

        $response = [
            'message' => 'Data SK Pengangkatan pertama berhasil disimpan.',
            'appointment' => $payload->appointment($appointment, $employee),
        ];
        if ($warning !== null) {
            $response['warning'] = $warning;
        }

        return response()->json($response);
    }

    public function uploadSk(
        UploadAppointmentSkRequest $request,
        Employee $employee,
        UploadAppointmentSkAction $action,
        EmployeeHistoryPayload $payload,
    ): JsonResponse {
        $appointment = $action->execute($employee, $request->file('file_sk'), $request);

        return response()->json([
            'message' => 'Berkas SK Pengangkatan berhasil diperbarui.',
            'appointment' => $payload->appointment($appointment, $employee),
        ]);
    }
}
