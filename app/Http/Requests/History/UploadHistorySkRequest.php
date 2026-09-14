<?php

namespace App\Http\Requests\History;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

class UploadHistorySkRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        $history = $this->route('rank') ?? $this->route('position') ?? $this->route('kgb');

        return $history instanceof Model
            && $this->user()?->hasPermission(filled($history->getAttribute('file_sk')) ? 'dokumen_sk.update' : 'dokumen_sk.create') === true;
    }

    public function rules(): array
    {
        return [
            'file_sk' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ];
    }

    public function attributes(): array
    {
        return [
            'file_sk' => 'Berkas SK',
        ];
    }
}
