<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\RefJenjangPendidikan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                    $history = $employee->educationHistories()->create([
                        'jenjang_id' => $jenjang ? $jenjang->id : null,
                        'nama_institusi' => $request->input('institusi'),
                        'jurusan' => $request->input('prodi'),
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
