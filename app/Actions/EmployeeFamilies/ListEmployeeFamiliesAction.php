<?php

namespace App\Actions\EmployeeFamilies;

use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Support\EmployeeFamilies\EmployeeFamilyPayload;
use Illuminate\Support\Collection;

class ListEmployeeFamiliesAction
{
    public function __construct(private readonly EmployeeFamilyPayload $payload) {}

    /**
     * Mengambil data keluarga aktif milik pegawai, urut terbaru untuk tampilan tab keluarga.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function execute(Employee $employee): Collection
    {
        return $employee->families()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (EmployeeFamily $family): array => $this->payload->response($family))
            ->values();
    }
}
