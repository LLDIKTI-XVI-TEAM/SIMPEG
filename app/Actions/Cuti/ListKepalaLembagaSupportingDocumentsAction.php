<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\KepalaLembagaSupportingDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Mendaftar dokumen aktif Kepala Lembaga dengan pagination pada database.
 */
class ListKepalaLembagaSupportingDocumentsAction
{
    /** @return LengthAwarePaginator<int, KepalaLembagaSupportingDocument> */
    public function execute(Employee $employee, int $perPage = 10): LengthAwarePaginator
    {
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;

        return KepalaLembagaSupportingDocument::query()
            ->when(
                $employee->is_kepala_lembaga,
                fn ($query) => $query->where('employee_id', $employee->id),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->with('uploader:id,name')
            ->latest()
            ->paginate($perPage)
            ->withQueryString();
    }
}
