<?php

namespace App\Http\Requests\History;

use App\Models\DisciplineRecord;
use Illuminate\Foundation\Http\FormRequest;

class UploadDisciplineRecordSkRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $record = $this->route('discipline');
        $permission = $record instanceof DisciplineRecord && filled($record->file_sk)
            ? 'dokumen_sk.update'
            : 'dokumen_sk.create';

        return $this->user()?->hasPermission($permission) ?? false;
    }

    public function rules(): array
    {
        return [
            'file_sk' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }
}
