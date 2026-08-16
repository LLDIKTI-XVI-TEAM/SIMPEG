<?php

namespace App\Http\Requests\Documents;

use App\Support\Documents\DocumentAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class CheckEmployeeDocumentImpactRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (DocumentAuthorization::allowsLocalApiBypass()) {
            return true;
        }

        return DocumentAuthorization::canDelete($this->user());
    }

    public function rules(): array
    {
        return [];
    }
}
