<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use App\Support\EmployeeValidationRules;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (app()->environment('local')
            && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        $employeeParam = $this->route('employee');

        if ($employeeParam instanceof Employee) {
            $employee = $employeeParam;
        } else {
            $id = $this->route('id') ?? $employeeParam;
            $employee = Employee::findOrFail($id);
        }

        $rules = EmployeeValidationRules::update($employee);

        // Aturan tambahan khusus form UI web
        if (! $this->wantsJson() && ! $this->is('api/*')) {
            // Riwayat kepegawaian bersifat append-only: id riwayat lama ditolak agar record tidak dapat diedit.
            $rules['pangkat_history_id'] = ['nullable', 'in:new'];
            $rules['jabatan_history_id'] = ['nullable', 'in:new'];
            $rules['kgb_history_id'] = ['nullable', 'in:new'];

            // Tanggal pensiun dapat diisi manual atau dikosongkan untuk kalkulasi otomatis dari BUP.
            // unset($rules['tanggal_pensiun']); // Dinonaktifkan agar user dapat input manual via form

            // Pangkat (Rank)
            $rules['pangkat_golongan_id'] = ['nullable', 'uuid', 'exists:ref_golongan,id'];
            $rules['pangkat_no_sk'] = ['nullable', 'string', 'max:255'];
            $rules['pangkat_tanggal_sk'] = ['nullable', 'date'];
            $rules['pangkat_tmt_pangkat'] = ['nullable', 'date'];
            $rules['file_sk_pangkat'] = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'];

            // Jabatan (Position)
            // Blok jabatan pada formulir ini selalu menambah riwayat penugasan baru, bukan
            // mengoreksi riwayat lama, sehingga jabatan nonaktif harus ditolak tanpa
            // pengecualian sama seperti pada pembuatan pegawai.
            $rules['jabatan_jabatan_id'] = ['nullable', 'uuid', Rule::exists('ref_jabatan', 'id')->where('is_active', true)];
            $rules['jabatan_nama_jabatan'] = ['nullable', 'string', 'max:255'];
            $rules['jabatan_jenis_jabatan_id'] = ['nullable', 'uuid', 'exists:ref_jenis_jabatan,id'];
            $rules['jabatan_eselon_id'] = ['nullable', 'uuid', 'exists:ref_eselon,id'];
            $rules['jabatan_unit_kerja_id'] = ['nullable', 'uuid', 'exists:ref_unit_kerja,id'];
            $rules['jabatan_kelas_jabatan'] = ['nullable', 'string', 'max:10'];
            $rules['jabatan_no_sk'] = ['nullable', 'string', 'max:255'];
            $rules['jabatan_tanggal_sk'] = ['nullable', 'date'];
            $rules['jabatan_tmt_jabatan'] = ['nullable', 'date'];
            $rules['file_sk_jabatan'] = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'];

            // KGB (Salary)
            $rules['kgb_gaji_pokok'] = ['nullable', 'numeric', 'min:0'];
            $rules['kgb_no_sk'] = ['nullable', 'string', 'max:255'];
            $rules['kgb_tanggal_sk'] = ['nullable', 'date'];
            $rules['kgb_tmt_kgb'] = ['nullable', 'date'];
            $rules['file_sk_kgb'] = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'];

            // Pengangkatan (Appointment)
            $rules['pengangkatan_jenis_pengangkatan'] = ['nullable', 'string', 'max:100'];
            $rules['pengangkatan_tmt_pengangkatan'] = ['nullable', 'date'];
            $rules['pengangkatan_no_sk'] = ['nullable', 'string', 'max:255'];
            $rules['pengangkatan_tanggal_sk'] = ['nullable', 'date'];
            $rules['file_sk_pengangkatan'] = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'];

            $isPppk = strcasecmp((string) $employee->jenisPegawai?->nama, 'PPPK') === 0;
            if ($isPppk) {
                $rules['pppk_tmt_pengangkatan'] = ['nullable', 'date'];
                $rules['tanggal_akhir_kontrak'] = ['nullable', 'date'];
            } else {
                $rules['pppk_tmt_pengangkatan'] = ['prohibited'];
                $rules['tanggal_akhir_kontrak'] = ['prohibited'];
            }

            // Override foto khusus web (file upload)
            $rules['foto'] = ['nullable', 'image', 'max:10240', 'mimes:jpg,jpeg,png'];
        }

        return $rules;
    }

    public function messages(): array
    {
        $appendOnly = 'Riwayat kepegawaian bersifat append-only — riwayat lama tidak dapat diubah, tambahkan riwayat baru.';

        return [
            'pangkat_history_id.in' => $appendOnly,
            'jabatan_history_id.in' => $appendOnly,
            'kgb_history_id.in' => $appendOnly,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('pppk_tmt_pengangkatan') && ! $this->has('tanggal_akhir_kontrak')) {
                return;
            }

            $employeeId = $this->route('id') ?? $this->route('employee');
            $employee = $employeeId instanceof Employee
                ? $employeeId
                : Employee::with('jenisPegawai')->find($employeeId);

            if ($employee === null || strcasecmp((string) $employee->jenisPegawai?->nama, 'PPPK') !== 0) {
                return;
            }

            $pppkAppointment = $employee->appointments()
                ->whereRaw('UPPER(jenis_pengangkatan) = ?', ['PPPK'])
                ->orderByDesc('tmt_pengangkatan')
                ->first();

            if ($this->filled('pppk_tmt_pengangkatan') && $pppkAppointment === null) {
                $validator->errors()->add(
                    'pppk_tmt_pengangkatan',
                    'Tambahkan SK Pengangkatan PPPK terlebih dahulu sebelum memperbarui TMT kontrak.'
                );

                return;
            }

            if (! $this->filled('tanggal_akhir_kontrak')) {
                return;
            }

            $tmt = $this->input('pppk_tmt_pengangkatan') ?? $pppkAppointment?->tmt_pengangkatan?->toDateString();
            if ($tmt !== null && Carbon::parse($this->input('tanggal_akhir_kontrak'))->lt(Carbon::parse($tmt))) {
                $validator->errors()->add(
                    'tanggal_akhir_kontrak',
                    'Tanggal akhir kontrak harus sama dengan atau setelah TMT Pengangkatan PPPK.'
                );
            }
        });
    }

    public function attributes(): array
    {
        return array_merge(EmployeeValidationRules::attributes(), [
            'pppk_tmt_pengangkatan' => 'TMT Pengangkatan PPPK',
            'tanggal_akhir_kontrak' => 'Tanggal akhir kontrak PPPK',
        ]);
    }
}
