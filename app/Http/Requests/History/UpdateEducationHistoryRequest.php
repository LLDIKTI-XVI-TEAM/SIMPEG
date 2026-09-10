<?php

namespace App\Http\Requests\History;

use App\Models\EducationHistory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEducationHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            return true;
        }

        return $this->user()?->hasPermission('employee_histories.update') ?? false;
    }

    public function rules(): array
    {
        $education = $this->route('education');
        $storedProgramStudiId = $education instanceof EducationHistory ? $education->program_studi_id : null;

        return [
            'jenjang_id' => ['required', 'uuid', 'exists:ref_jenjang_pendidikan,id'],
            'nama_institusi' => ['required', 'string', 'max:255'],
            'program_studi_id' => ['nullable', 'uuid', Rule::exists('ref_program_studi', 'id')
                ->where(fn ($query) => $this->allowStoredProgramStudi($query, $storedProgramStudiId))],
            'tahun_lulus' => ['required', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'no_ijazah' => ['nullable', 'string', 'max:100'],
        ];
    }

    private function allowStoredProgramStudi(mixed $query, ?string $storedProgramStudiId): void
    {
        $query->where('is_active', true);

        if ($storedProgramStudiId !== null) {
            $query->orWhere('id', $storedProgramStudiId);
        }
    }
}
