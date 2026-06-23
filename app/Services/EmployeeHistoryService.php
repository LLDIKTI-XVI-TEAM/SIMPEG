<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\SalaryHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EmployeeHistoryService
{
    /**
     * Menambah riwayat pangkat secara append-only dan menjaga hanya satu data terbaru.
     */
    public function createRankHistory(Employee $employee, array $data, ?Request $request = null): RankHistory
    {
        return DB::transaction(function () use ($employee, $data, $request): RankHistory {
            // Kunci baris pegawai agar dua penulisan paralel tidak sama-sama menyisakan riwayat terbaru.
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $currentLatest = $employee->rankHistories()->where('is_latest', true)->first();
            // Riwayat terbaru ditentukan dari TMT, bukan urutan input, agar backfill SK lama tidak merusak snapshot.
            $isLatest = $currentLatest === null
                || Carbon::parse($data['tmt_pangkat'])->greaterThanOrEqualTo($currentLatest->tmt_pangkat);

            if ($isLatest) {
                $employee->rankHistories()->update(['is_latest' => false]);
            }

            $history = $employee->rankHistories()->create([
                ...Arr::only($data, ['golongan_id', 'tmt_pangkat', 'no_sk', 'tanggal_sk', 'file_sk']),
                'is_latest' => $isLatest,
            ]);

            if ($isLatest) {
                $golongan = RefGolongan::findOrFail($data['golongan_id']);
                $employee->update([
                    'golongan_terakhir' => $golongan->kode,
                    'pangkat_terakhir' => $golongan->nama,
                    // Aturan domain EWS: jadwal kenaikan pangkat reguler dihitung 4 tahun dari TMT pangkat terbaru.
                    'tanggal_kenaikan_pangkat_berikutnya' => Carbon::parse($data['tmt_pangkat'])->addYears(4)->toDateString(),
                ]);
            }

            AuditService::log('CREATE', 'RankHistory', $history->id, null, $history->toArray(), $request);

            return $history->refresh();
        });
    }

    /**
     * Menambah riwayat jabatan dan menghitung ulang tanggal pensiun dari BUP jenis jabatan.
     */
    public function createPositionHistory(Employee $employee, array $data, ?Request $request = null): PositionHistory
    {
        return DB::transaction(function () use ($employee, $data, $request): PositionHistory {
            // Kunci baris pegawai agar dua penulisan paralel tidak sama-sama menyisakan riwayat terbaru.
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $currentLatest = $employee->positionHistories()->where('is_latest', true)->first();
            // Riwayat terbaru ditentukan dari TMT, bukan urutan input, agar backfill SK lama tidak merusak snapshot.
            $isLatest = $currentLatest === null
                || Carbon::parse($data['tmt_jabatan'])->greaterThanOrEqualTo($currentLatest->tmt_jabatan);

            if ($isLatest) {
                $employee->positionHistories()->update(['is_latest' => false]);
            }

            $history = $employee->positionHistories()->create([
                ...Arr::only($data, [
                    'nama_jabatan',
                    'jenis_jabatan_id',
                    'eselon_id',
                    'unit_kerja_id',
                    'tmt_jabatan',
                    'no_sk',
                    'tanggal_sk',
                    'file_sk',
                ]),
                'is_latest' => $isLatest,
            ]);

            if ($isLatest) {
                $jenisJabatan = RefJenisJabatan::findOrFail($data['jenis_jabatan_id']);
                $employee->update([
                    'jabatan_terakhir' => $data['nama_jabatan'],
                    'tanggal_pensiun' => $employee->tanggal_lahir->copy()
                        ->addYears($jenisJabatan->maks_usia_pensiun)
                        ->toDateString(),
                ]);
            }

            AuditService::log('CREATE', 'PositionHistory', $history->id, null, $history->toArray(), $request);

            return $history->refresh();
        });
    }

    /**
     * Menambah riwayat KGB dan menghitung TMT KGB berikutnya untuk kebutuhan EWS.
     */
    public function createKgbHistory(Employee $employee, array $data, ?Request $request = null): SalaryHistory
    {
        return DB::transaction(function () use ($employee, $data, $request): SalaryHistory {
            // Kunci baris pegawai agar dua penulisan paralel tidak sama-sama menyisakan riwayat terbaru.
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $currentLatest = $employee->salaryHistories()->where('is_latest', true)->first();
            // Riwayat terbaru ditentukan dari TMT, bukan urutan input, agar backfill SK lama tidak merusak snapshot.
            $isLatest = $currentLatest === null
                || Carbon::parse($data['tmt_kgb'])->greaterThanOrEqualTo($currentLatest->tmt_kgb);

            if ($isLatest) {
                $employee->salaryHistories()->update(['is_latest' => false]);
            }

            $history = $employee->salaryHistories()->create([
                ...Arr::only($data, ['tmt_kgb', 'gaji_pokok', 'no_sk', 'tanggal_sk', 'file_sk']),
                'is_latest' => $isLatest,
            ]);

            if ($isLatest) {
                $employee->update([
                    // Aturan domain EWS: jadwal KGB berikutnya dihitung 2 tahun dari TMT KGB terbaru.
                    'tanggal_kgb_berikutnya' => Carbon::parse($data['tmt_kgb'])->addYears(2)->toDateString(),
                ]);
            }

            AuditService::log('CREATE', 'SalaryHistory', $history->id, null, $history->toArray(), $request);

            return $history->refresh();
        });
    }
}
