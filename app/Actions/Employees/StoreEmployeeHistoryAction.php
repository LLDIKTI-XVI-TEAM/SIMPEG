<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\RefJenjangPendidikan;
use App\Models\RefProgramStudi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreEmployeeHistoryAction
{
    /**
     * Menyimpan riwayat pegawai tambahan, seperti pendidikan.
     */
    public function execute(Employee $employee, Request $request)
    {
        return DB::transaction(function () use ($employee, $request) {
            $type = $request->input('type');

            switch ($type) {
                case 'pendidikan':
                    $jenjang = RefJenjangPendidikan::where('nama', $request->input('tingkat'))->first();
                    $programStudiId = $request->input('program_studi_id');
                    $programStudi = $programStudiId
                        ? RefProgramStudi::query()->whereKey($programStudiId)->where('is_active', true)->first()
                        : null;

                    if ($programStudiId && $programStudi === null) {
                        throw ValidationException::withMessages([
                            'program_studi_id' => 'Program studi tidak tersedia atau sudah nonaktif.',
                        ]);
                    }

                    $history = $employee->educationHistories()->create([
                        'jenjang_id' => $jenjang ? $jenjang->id : null,
                        'program_studi_id' => $programStudi?->id,
                        'nama_institusi' => $request->input('institusi'),
                        'jurusan' => $programStudi?->nama,
                        'tahun_lulus' => $request->input('lulus'),
                        'no_ijazah' => $request->input('no_ijazah'),
                    ]);
                    break;
                default:
                    throw new \Exception('Tipe riwayat tidak valid atau sudah dimigrasikan ke endpoint khusus.');
            }

            return $history;
        });
    }
}
