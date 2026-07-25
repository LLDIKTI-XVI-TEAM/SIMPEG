<?php

namespace App\Services;

use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EwsConfig;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\SalaryHistory;
use App\Services\Employees\TmtCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EmployeeHistoryService
{
    public function __construct(
        private readonly EmployeeFileStorageService $files,
        private readonly TmtCalculatorService $tmtCalculator,
    ) {}

    /**
     * Menambah riwayat pangkat secara append-only dan menjaga snapshot pangkat pegawai.
     */
    public function createRankHistory(Employee $employee, array $data, ?Request $request = null): RankHistory
    {
        $data = $this->storeSkUpload($data);

        return DB::transaction(function () use ($employee, $data, $request): RankHistory {
            $golongan = RefGolongan::findOrFail($data['golongan_id']);
            // Kunci baris pegawai agar dua penulisan paralel tidak sama-sama menyisakan riwayat terbaru.
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $history = $employee->rankHistories()->create([
                ...Arr::only($data, ['golongan_id', 'tmt_pangkat', 'no_sk', 'tanggal_sk', 'file_sk']),
                'is_latest' => false,
            ]);

            // Pilih ulang dari seluruh riwayat bertanggal agar flag lama yang keliru dan input backfill tidak dipercaya.
            $latest = $employee->rankHistories()
                ->whereNotNull('tmt_pangkat')
                ->orderByDesc('tmt_pangkat')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();
            $employee->rankHistories()->update(['is_latest' => false]);
            if ($latest !== null) {
                $employee->rankHistories()->whereKey($latest->id)->update(['is_latest' => true]);
            }

            if ($latest !== null) {
                $latestGolongan = RefGolongan::findOrFail($latest->golongan_id);
                $employee->update([
                    'golongan_terakhir' => $latestGolongan->kode,
                    'pangkat_terakhir' => $latestGolongan->nama,
                ]);
            }

            $this->tmtCalculator->syncForEmployee($employee);

            if ($history->file_sk) {
                $employee->documents()->create([
                    'jenis_dokumen' => 'sk_pangkat',
                    'nama_dokumen' => 'SK Kenaikan Pangkat '.$golongan->kode,
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
     * Menetapkan riwayat pangkat dengan TMT paling baru sebagai snapshot pegawai dan target EWS.
     *
     * Riwayat dengan TMT kosong tidak mengubah snapshot yang sudah ada.
     */
    public function reconcileRankSnapshot(Employee $employee): ?RankHistory
    {
        $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
        $latest = $employee->rankHistories()
            ->with('golongan')
            ->whereNotNull('tmt_pangkat')
            ->orderByDesc('tmt_pangkat')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return null;
        }

        $employee->rankHistories()->update(['is_latest' => false]);
        // Pembaruan massal di atas tidak memperbarui atribut model yang sudah dimuat.
        // Gunakan query eksplisit agar kandidat terbaru selalu diaktifkan kembali.
        $employee->rankHistories()->whereKey($latest->id)->update(['is_latest' => true]);

        $employee->update([
            'golongan_terakhir' => $latest->golongan->kode,
            'pangkat_terakhir' => $latest->golongan->nama,
            // Aturan domain EWS: jadwal kenaikan pangkat reguler dihitung 4 tahun dari TMT pangkat terbaru.
            'tanggal_kenaikan_pangkat_berikutnya' => $latest->tmt_pangkat->copy()
                ->addYears($this->configYears('pangkat_required_years', 4))
                ->toDateString(),
        ]);

        return $latest->refresh();
    }

    /**
     * Menambah riwayat jabatan dan menghitung ulang tanggal pensiun dari BUP jabatan atau jenis jabatannya.
     */
    public function createPositionHistory(Employee $employee, array $data, ?Request $request = null): PositionHistory
    {
        $data = $this->storeSkUpload($data);

        return DB::transaction(function () use ($employee, $data, $request): PositionHistory {
            $jabatan = RefJabatan::findOrFail($data['jabatan_id']);
            $jenisJabatanId = $data['jenis_jabatan_id'] ?? $jabatan->jenis_jabatan_id;
            // Kunci baris pegawai agar dua penulisan paralel tidak sama-sama menyisakan riwayat terbaru.
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $history = $employee->positionHistories()->create([
                ...Arr::only($data, [
                    'eselon_id',
                    'unit_kerja_id',
                    'kelas_jabatan',
                    'tmt_jabatan',
                    'no_sk',
                    'tanggal_sk',
                    'file_sk',
                ]),
                'jabatan_id' => $jabatan->id,
                'nama_jabatan' => $jabatan->nama,
                'jenis_jabatan_id' => $jenisJabatanId,
                'is_latest' => false,
            ]);

            // Urutan stabil ini memastikan TMT sama tetap menghasilkan satu sumber snapshot yang deterministik.
            $latest = $employee->positionHistories()
                ->whereNotNull('tmt_jabatan')
                ->orderByDesc('tmt_jabatan')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();
            $employee->positionHistories()->update(['is_latest' => false]);
            if ($latest !== null) {
                $employee->positionHistories()->whereKey($latest->id)->update(['is_latest' => true]);
            }

            if ($latest !== null) {
                $latestJabatan = RefJabatan::findOrFail($latest->jabatan_id);
                $employee->update([
                    'jabatan_terakhir' => $latestJabatan->nama,
                    'kelas_jabatan_terakhir' => $latest->kelas_jabatan ?? $employee->kelas_jabatan_terakhir,
                    'kelas_jabatan' => $latest->kelas_jabatan ?? $employee->kelas_jabatan_terakhir,
                ]);
            }

            $this->tmtCalculator->syncForEmployee($employee);

            if ($history->file_sk) {
                $employee->documents()->create([
                    'jenis_dokumen' => 'sk_jabatan',
                    'nama_dokumen' => 'SK Kenaikan Jabatan '.$jabatan->nama,
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
            $history = $employee->salaryHistories()->create([
                ...Arr::only($data, ['tmt_kgb', 'gaji_pokok', 'no_sk', 'tanggal_sk', 'file_sk']),
                'is_latest' => false,
            ]);

            // TMT null tidak boleh menjadi terbaru; semua flag dibangun ulang dari sumber bertanggal yang sah.
            $latest = $employee->salaryHistories()
                ->whereNotNull('tmt_kgb')
                ->orderByDesc('tmt_kgb')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();
            $employee->salaryHistories()->update(['is_latest' => false]);
            if ($latest !== null) {
                $employee->salaryHistories()->whereKey($latest->id)->update(['is_latest' => true]);
            }

            $this->tmtCalculator->syncForEmployee($employee);

            if ($history->file_sk) {
                $employee->documents()->create([
                    'jenis_dokumen' => 'sk_kgb',
                    'nama_dokumen' => 'SK KGB TMT '.($history->tmt_kgb ? $history->tmt_kgb->format('d-m-Y') : ''),
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
        // Proses upload file SK terlebih dahulu (jika ada file baru)
        $data = $this->storeSkUpload($data);

        // Jika user memilih dari arsip dokumen, gunakan file_path dokumen sebagai file_sk
        if (empty($data['file_sk']) && ! empty($data['dokumen_id'])) {
            $doc = Document::find($data['dokumen_id']);
            $data['file_sk'] = $doc?->file_path;
        }
        unset($data['dokumen_id']);

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

            if ($record->file_sk) {
                $employee->documents()->create([
                    'jenis_dokumen' => 'sk_hukuman_disiplin',
                    'nama_dokumen' => 'SK Hukuman Disiplin '.$record->jenis_hukuman,
                    'nomor_dokumen' => $record->no_sk,
                    'tanggal_dokumen' => $record->tanggal_sk,
                    'file_path' => $record->file_sk,
                    'keterangan' => 'Unggah otomatis dari riwayat hukuman disiplin.',
                ]);
            }

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

    private function configYears(string $key, int $default): int
    {
        return max(1, (int) EwsConfig::getVal($key, (string) $default));
    }

    /**
     * Upload SK disimpan sebelum transaksi data agar model hanya menerima path relatif yang aman.
     *
     * @param  array<string, mixed>  $data
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
