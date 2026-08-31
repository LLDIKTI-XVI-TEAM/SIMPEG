<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Appointment\SaveAppointmentAction;
use App\Actions\Appointment\UploadAppointmentSkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Appointment\SaveAppointmentRequest;
use App\Http\Requests\Appointment\UploadAppointmentSkRequest;
use App\Models\Employee;
use App\Support\Histories\EmployeeHistoryPayload;
use Illuminate\Http\JsonResponse;

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
        $appointment = $action->execute(
            $employee,
            $request->safe()->except(['file_sk']),
            $request->file('file_sk'),
            $request,
        );

        return response()->json([
            'message' => 'Data SK Pengangkatan pertama berhasil disimpan.',
            'appointment' => $payload->appointment($appointment, $employee),
        ]);
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
