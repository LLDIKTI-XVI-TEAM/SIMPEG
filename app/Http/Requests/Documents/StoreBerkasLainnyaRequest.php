<?php

namespace App\Http\Requests\Documents;

use App\Support\Documents\DocumentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreBerkasLainnyaRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        return [
            'nama_dokumen'     => ['required', 'string', 'max:255'],
            'kategori_dokumen' => ['required', 'string', 'in:ijazah,ktp_kk,lainnya'],
            'nomor_dokumen'    => ['nullable', 'string', 'max:100'],
            'tanggal_terbit'   => ['nullable', 'date'],
            'keterangan'       => ['nullable', 'string', 'max:500'],
            'berkas'           => [
                'required',
                File::types(DocumentCategory::ALLOWED_FILE_TYPES)->max('10mb'),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'nama_dokumen'     => 'nama berkas',
            'kategori_dokumen' => 'kategori',
            'berkas'           => 'berkas',
        ];
    }
}
