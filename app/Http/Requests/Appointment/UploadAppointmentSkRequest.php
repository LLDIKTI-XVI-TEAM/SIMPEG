<?php

namespace App\Http\Requests\Appointment;

use App\Models\Appointment;
use App\Models\Employee;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadAppointmentSkRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Selaras dengan seluruh FormRequest riwayat pada mode API lokal tanpa autentikasi.
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $user = $this->user();
        if (! $user) {
            return false;
        }

        $employee = $this->route('employee');
        if (! $employee instanceof Employee) {
            return false;
        }

        $appointment = Appointment::query()
            ->where('employee_id', $employee->id)
            ->orderBy('tmt_pengangkatan')
            ->orderBy('id')
            ->first();

        return $appointment !== null
            && $user->hasPermission(filled($appointment->file_sk) ? 'dokumen_sk.update' : 'dokumen_sk.create');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file_sk' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file_sk.required' => 'Berkas SK pengangkatan wajib dipilih.',
            'file_sk.mimes' => 'Format berkas SK harus berupa PDF, JPG, JPEG, atau PNG.',
            'file_sk.max' => 'Ukuran berkas SK tidak boleh lebih dari 10 MB.',
        ];
    }
}
