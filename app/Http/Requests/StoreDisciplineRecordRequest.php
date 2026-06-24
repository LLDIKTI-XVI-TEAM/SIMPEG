<?php

namespace App\Http\Requests;

use App\Support\SkFilePathRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDisciplineRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mutasi hukuman disiplin hanya boleh dilakukan pengelola data kepegawaian.
        $user = $this->user();

        return $user !== null
            && in_array($user->role, ['super_admin', 'admin_kepegawaian'], true);
    }

    public function rules(): array
    {
        return [
            'jenis_hukuman' => ['required', Rule::in(['Ringan', 'Sedang', 'Berat'])],
            'deskripsi' => ['required', 'string', 'max:2000'],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_berakhir' => ['nullable', 'date', 'after_or_equal:tanggal_mulai'],
            'no_sk' => ['required', 'string', 'max:100'],
            'tanggal_sk' => ['required', 'date'],
            'file_sk' => SkFilePathRules::nullableUploadOrControlledPath(),
        ];
    }
}
