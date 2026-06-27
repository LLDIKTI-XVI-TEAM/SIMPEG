<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\DisciplineRecord;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\SalaryHistory;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EmployeeHistoryService
{
    public function __construct(private readonly EmployeeFileStorageService $files) {}

    /**
     * Menambah riwayat pangkat secara append-only dan menjaga hanya satu data terbaru.
     */
    public function createRankHistory(Employee $employee, array $data, ?Request $request = null): RankHistory
    {
        $data = $this->storeSkUpload($data);

        return DB::transaction(function () use ($employee, $data, $request): RankHistory {
            $golongan = RefGolongan::findOrFail($data['golongan_id']);
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
                $employee->update([
                    'golongan_terakhir' => $golongan->kode,
                    'pangkat_terakhir' => $golongan->nama,
                    // Aturan domain EWS: jadwal kenaikan pangkat reguler dihitung 4 tahun dari TMT pangkat terbaru.
                    'tanggal_kenaikan_pangkat_berikutnya' => Carbon::parse($data['tmt_pangkat'])->addYears(4)->toDateString(),
                ]);
            }

            if ($history->file_sk) {
                $employee->documents()->create([
                    'jenis_dokumen' => 'sk_pangkat',
                    'nama_dokumen' => 'SK Kenaikan Pangkat ' . $golongan->kode,
                    'nomor_dokumen' => $history->no_sk,
                    'tanggal_dokumen' => $history->tanggal_sk,
                    'file_path' => $history->file_sk,
                    'keterangan' => 'Unggah otomatis dari riwayat kepangkatan.',
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
        $data = $this->storeSkUpload($data);

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

            if ($history->file_sk) {
                $employee->documents()->create([
                    'jenis_dokumen' => 'sk_jabatan',
                    'nama_dokumen' => 'SK Kenaikan Jabatan ' . $history->nama_jabatan,
                    'nomor_dokumen' => $history->no_sk,
                    'tanggal_dokumen' => $history->tanggal_sk,
                    'file_path' => $history->file_sk,
                    'keterangan' => 'Unggah otomatis dari riwayat jabatan.',
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
        $data = $this->storeSkUpload($data);

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

            if ($history->file_sk) {
                $employee->documents()->create([
                    'jenis_dokumen' => 'sk_kgb',
                    'nama_dokumen' => 'SK KGB TMT ' . ($history->tmt_kgb ? $history->tmt_kgb->format('d-m-Y') : ''),
                    'nomor_dokumen' => $history->no_sk,
                    'tanggal_dokumen' => $history->tanggal_sk,
                    'file_path' => $history->file_sk,
                    'keterangan' => 'Unggah otomatis dari riwayat kenaikan gaji berkala.',
                ]);
            }

            AuditService::log('CREATE', 'SalaryHistory', $history->id, null, $history->toArray(), $request);

            return $history->refresh();
        });
    }

    /**
     * Menambah riwayat hukuman disiplin secara append-only dan menghitung status aktif dari tanggal berakhir.
     */
    public function createDisciplineRecord(Employee $employee, array $data, ?Request $request = null): DisciplineRecord
    {
        $data = $this->storeSkUpload($data);

        return DB::transaction(function () use ($employee, $data, $request): DisciplineRecord {
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $endDate = isset($data['tanggal_berakhir']) && $data['tanggal_berakhir'] !== null
                ? Carbon::parse($data['tanggal_berakhir'])
                : null;

            $record = $employee->disciplineRecords()->create([
                ...Arr::only($data, [
                    'jenis_hukuman',
                    'deskripsi',
                    'tanggal_mulai',
                    'tanggal_berakhir',
                    'no_sk',
                    'tanggal_sk',
                    'file_sk',
                ]),
                'is_active' => $endDate === null || $endDate->greaterThanOrEqualTo(Carbon::today()),
            ]);

            AuditService::log('CREATE', 'DisciplineRecord', $record->id, null, Arr::only($record->toArray(), [
                'id',
                'employee_id',
                'jenis_hukuman',
                'tanggal_mulai',
                'tanggal_berakhir',
                'tanggal_sk',
                'is_active',
            ]), $request);

            return $record->refresh();
        });
    }

    /**
     * Upload SK disimpan sebelum transaksi data agar model hanya menerima path relatif yang aman.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function storeSkUpload(array $data): array
    {
        if (($data['file_sk'] ?? null) instanceof UploadedFile) {
            $data['file_sk'] = $this->files->storeSk($data['file_sk']);
        }

        return $data;
    }
}
