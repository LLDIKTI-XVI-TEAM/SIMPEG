<?php

namespace App\Http\Requests\Employee;

use App\Support\EmployeeValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Bypass otorisasi di environment lokal saat flag disable auth aktif.
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $user = $this->user();

        return $user !== null
            && $user->hasPermission('employees.create');
    }

    public function rules(): array
    {
        $rules = EmployeeValidationRules::create();
        // Status awal bukan input create. Seluruh perubahan setelah pegawai dibuat
        // wajib melalui lifecycle resmi agar histori, audit, dan otorisasi tidak dilewati.
        $rules['status_aktif'] = ['prohibited'];
        $rules['status_pegawai_id'] = ['prohibited'];
        $rules['status_keterangan'] = ['prohibited'];
        $rules['status_note'] = ['prohibited'];
        $rules['status_tanggal'] = ['prohibited'];
        $rules['status_berkas_path'] = ['prohibited'];
        $rules['status_nomor_berkas'] = ['prohibited'];

        // Aturan tambahan khusus form UI web
        if (! $this->wantsJson() && ! $this->is('api/*')) {
            // Pangkat (Rank)
            $rules['pangkat_golongan_id'] = ['nullable', 'required_with:pangkat_no_sk,pangkat_tanggal_sk,pangkat_tmt_pangkat', 'uuid', 'exists:ref_golongan,id'];
            $rules['pangkat_no_sk'] = ['nullable', 'required_with:pangkat_golongan_id,pangkat_tanggal_sk,pangkat_tmt_pangkat', 'string', 'max:255'];
            $rules['pangkat_tanggal_sk'] = ['nullable', 'required_with:pangkat_golongan_id,pangkat_no_sk,pangkat_tmt_pangkat', 'date'];
            $rules['pangkat_tmt_pangkat'] = ['nullable', 'required_with:pangkat_golongan_id,pangkat_no_sk,pangkat_tanggal_sk', 'date'];
            $rules['file_sk_pangkat'] = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'];

            // Jabatan (Position)
            // Penugasan pegawai baru hanya boleh memakai jabatan yang masih aktif, sama seperti
            // penambahan riwayat jabatan lewat endpoint riwayat, agar jabatan yang sudah
            // dinonaktifkan tidak bisa masuk lewat jalur pembuatan pegawai.
            $rules['jabatan_jabatan_id'] = ['nullable', 'uuid', Rule::exists('ref_jabatan', 'id')->where('is_active', true)];
            $rules['jabatan_nama_jabatan'] = ['nullable', 'string', 'max:255'];
            $rules['jabatan_jenis_jabatan_id'] = ['nullable', 'required_with:jabatan_unit_kerja_id,jabatan_no_sk,jabatan_tanggal_sk,jabatan_tmt_jabatan', 'uuid', 'exists:ref_jenis_jabatan,id'];
            $rules['jabatan_eselon_id'] = ['nullable', 'uuid', 'exists:ref_eselon,id'];
            $rules['jabatan_unit_kerja_id'] = ['nullable', 'required_with:jabatan_jenis_jabatan_id,jabatan_no_sk,jabatan_tanggal_sk,jabatan_tmt_jabatan', 'uuid', 'exists:ref_unit_kerja,id'];
            $rules['jabatan_kelas_jabatan'] = ['nullable', 'string', 'max:10'];
            $rules['jabatan_no_sk'] = ['nullable', 'required_with:jabatan_jenis_jabatan_id,jabatan_unit_kerja_id,jabatan_tanggal_sk,jabatan_tmt_jabatan', 'string', 'max:255'];
            $rules['jabatan_tanggal_sk'] = ['nullable', 'required_with:jabatan_jenis_jabatan_id,jabatan_unit_kerja_id,jabatan_no_sk,jabatan_tmt_jabatan', 'date'];
            $rules['jabatan_tmt_jabatan'] = ['nullable', 'required_with:jabatan_jenis_jabatan_id,jabatan_unit_kerja_id,jabatan_no_sk,jabatan_tanggal_sk', 'date'];
            $rules['file_sk_jabatan'] = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'];

            // KGB (Salary)
            $rules['kgb_gaji_pokok'] = ['nullable', 'required_with:kgb_no_sk,kgb_tanggal_sk,kgb_tmt_kgb', 'numeric', 'min:0'];
            $rules['kgb_no_sk'] = ['nullable', 'required_with:kgb_gaji_pokok,kgb_tanggal_sk,kgb_tmt_kgb', 'string', 'max:255'];
            $rules['kgb_tanggal_sk'] = ['nullable', 'required_with:kgb_gaji_pokok,kgb_no_sk,kgb_tmt_kgb', 'date'];
            $rules['kgb_tmt_kgb'] = ['nullable', 'required_with:kgb_gaji_pokok,kgb_no_sk,kgb_tanggal_sk', 'date'];
            $rules['file_sk_kgb'] = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'];

            // Pengangkatan (Appointment)
            $rules['pengangkatan_jenis_pengangkatan'] = ['nullable', 'required_with:pengangkatan_tmt_pengangkatan,pengangkatan_no_sk,pengangkatan_tanggal_sk', 'string', 'max:100'];
            $rules['pengangkatan_tmt_pengangkatan'] = ['nullable', 'required_with:pengangkatan_jenis_pengangkatan,pengangkatan_no_sk,pengangkatan_tanggal_sk', 'date'];
            $rules['pengangkatan_no_sk'] = ['nullable', 'required_with:pengangkatan_jenis_pengangkatan,pengangkatan_tmt_pengangkatan,pengangkatan_tanggal_sk', 'string', 'max:255'];
            $rules['pengangkatan_tanggal_sk'] = ['nullable', 'required_with:pengangkatan_jenis_pengangkatan,pengangkatan_tmt_pengangkatan,pengangkatan_no_sk', 'date'];
            $rules['file_sk_pengangkatan'] = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'];

            // Berkas Lainnya (KTP, KK, SK Mutasi, SK Pensiun, atau jenis manual)
            $rules['berkas_lainnya_jenis'] = ['nullable', 'required_with:berkas_lainnya_tanggal,file_berkas_lainnya', 'string', 'in:KTP,KK,SK Mutasi,SK Pensiun,Lainnya'];
            $rules['berkas_lainnya_jenis_manual'] = ['nullable', 'required_if:berkas_lainnya_jenis,Lainnya', 'string', 'max:100'];
            $rules['berkas_lainnya_nomor'] = ['nullable', 'string', 'max:100'];
            $rules['berkas_lainnya_deskripsi'] = ['nullable', 'string', 'max:2000'];
            $rules['berkas_lainnya_tanggal'] = ['nullable', 'required_with:berkas_lainnya_jenis,file_berkas_lainnya', 'date'];
            $rules['file_berkas_lainnya'] = ['nullable', 'required_with:berkas_lainnya_jenis,berkas_lainnya_tanggal', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png'];

            // Override foto khusus web (file upload)
            $rules['foto'] = ['nullable', 'image', 'max:10240', 'mimes:jpg,jpeg,png'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return array_merge(EmployeeValidationRules::attributes(), [
            'file_sk_pangkat' => 'File SK Pangkat',
            'file_sk_jabatan' => 'File SK Jabatan',
            'file_sk_kgb' => 'File SK KGB',
            'file_sk_pengangkatan' => 'File SK Pengangkatan',
            'file_berkas_lainnya' => 'File Berkas Lainnya',
        ]);
    }
}
