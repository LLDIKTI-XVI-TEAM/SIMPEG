<?php

namespace App\Actions\Cuti;

use App\Models\Employee;

final class LookupManualExternalApproversAction
{
    private const RESULT_LIMIT = 15;

    /**
     * Mencari identitas minimum approver tanpa mengirim kontak, alamat, atau atribut pegawai sensitif lainnya.
     *
     * @return list<array{id:string,nama_lengkap:string,nip:string,jabatan_terakhir:?string,status_aktif:?string}>
     */
    public function execute(string $query): array
    {
        $escaped = addcslashes($query, '\\%_');
        $pattern = "%{$escaped}%";

        return Employee::query()
            ->select(['id', 'nama_lengkap', 'nip', 'jabatan_terakhir', 'status_aktif'])
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
     * @return array{id:string,nama_lengkap:string,nip:string,jabatan_terakhir:?string,status_aktif:?string}
     */
    private function approverPayload(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'nama_lengkap' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'jabatan_terakhir' => $employee->jabatan_terakhir,
            'status_aktif' => $employee->status_aktif,
        ];
    }
}
