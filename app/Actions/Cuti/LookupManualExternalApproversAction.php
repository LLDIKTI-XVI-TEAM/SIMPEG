<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\User;
use App\Services\Cuti\LeaveUsageAuthorizationService;

final class LookupManualExternalApproversAction
{
    private const RESULT_LIMIT = 15;

    public function __construct(private readonly LeaveUsageAuthorizationService $authorization) {}

    /**
     * Mencari identitas minimum approver tanpa mengirim kontak, alamat, atau atribut pegawai sensitif lainnya.
     *
     * Kandidat boleh berada di luar scope pemilik fakta karena atasan/PYBMC bukan pemilik cuti tersebut.
     *
     * @return list<array{id:string,nama_lengkap:string,nip:string,jabatan_terakhir:?string}>
     */
    public function execute(string $query, User $actor): array
    {
        $this->authorization->assertCanManageManual($actor);
        $escaped = addcslashes($query, '\\%_');
        $pattern = "%{$escaped}%";

        return Employee::query()
            ->select(['id', 'nama_lengkap', 'nip', 'jabatan_terakhir'])
            ->where(function ($employeeQuery) use ($pattern): void {
                $employeeQuery
                    ->whereRaw("nama_lengkap ILIKE ? ESCAPE E'\\\\'", [$pattern])
                    ->orWhereRaw("nip ILIKE ? ESCAPE E'\\\\'", [$pattern]);
            })
            ->orderBy('nama_lengkap')
            ->orderBy('id')
            ->limit(self::RESULT_LIMIT)
            ->get()
            ->map($this->approverPayload(...))
            ->values()
            ->all();
    }

    /**
     * Membatasi respons autocomplete pada identitas kerja yang diperlukan untuk memilih approver.
     *
     * @return array{id:string,nama_lengkap:string,nip:string,jabatan_terakhir:?string}
     */
    private function approverPayload(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'nama_lengkap' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'jabatan_terakhir' => $employee->jabatan_terakhir,
        ];
    }
}
