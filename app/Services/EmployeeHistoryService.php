<?php

namespace App\Services;

use App\Models\DisciplineRecord;
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
use Illuminate\Validation\ValidationException;

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
        [$data, $uploadedSkPath] = $this->storeSkUploadWithPath($data);

        return $this->transactionWithSkCleanup($uploadedSkPath, function () use ($employee, $data, $request): RankHistory {
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
        [$data, $uploadedSkPath] = $this->storeSkUploadWithPath($data);

        return $this->transactionWithSkCleanup($uploadedSkPath, function () use ($employee, $data, $request): PositionHistory {
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
        [$data, $uploadedSkPath] = $this->storeSkUploadWithPath($data);

        return $this->transactionWithSkCleanup($uploadedSkPath, function () use ($employee, $data, $request): SalaryHistory {
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
        $user = $request?->user() ?? auth()->user();
        $canCreateDoc = $user === null || $user->hasPermission('dokumen_sk.create');
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            $canCreateDoc = true;
        }
        if (! $canCreateDoc) {
            // Tanpa hak dokumen, abaikan berkas dan dokumen_id agar riwayat tetap tersimpan tanpa lampiran
            unset($data['dokumen_id'], $data['file_sk']);
        }

        $document = null;
        if (! empty($data['dokumen_id'])) {
            // Arsip yang dipakai ulang wajib tetap menjadi SK disiplin milik pegawai ini.
            $document = $employee->documents()
                ->whereKey($data['dokumen_id'])
                ->where('jenis_dokumen', 'sk_hukuman_disiplin')
                ->first();
            if ($document === null) {
                throw ValidationException::withMessages([
                    'dokumen_id' => 'Dokumen harus berupa SK Hukuman Disiplin milik pegawai yang sedang diproses.',
                ]);
            }

            if (empty($data['file_sk'])) {
                $data['file_sk'] = $document->file_path;
            }
        }

        if (($data['file_sk'] ?? null) instanceof UploadedFile && $document !== null) {
            throw ValidationException::withMessages([
                'file_sk' => 'Unggahan baru tidak dapat digabung dengan pilihan dokumen arsip.',
            ]);
        }

        if (is_string($data['file_sk'] ?? null)) {
            $path = $data['file_sk'];
            // Path string hanya kompatibilitas arsip internal; kepemilikan, kategori, dan path
            // wajib cocok agar pemanggil service tidak dapat melewati validasi HTTP.
            $pathDocument = $employee->documents()
                ->where('jenis_dokumen', 'sk_hukuman_disiplin')
                ->where('file_path', $path)
                ->first();
            if ($pathDocument === null || ($document !== null && ! hash_equals($document->file_path, $path))) {
                throw ValidationException::withMessages([
                    'file_sk' => 'Path file harus merujuk SK Hukuman Disiplin milik pegawai yang sedang diproses.',
                ]);
            }
        }

        // Proses upload file SK terlebih dahulu (jika ada file baru)
        [$data, $uploadedSkPath] = $this->storeSkUploadWithPath($data);

        unset($data['dokumen_id']);

        // Hanya file yang baru diunggah yang boleh dihapus saat rollback; file dari
        // arsip dokumen adalah milik data lain dan tidak boleh ikut terhapus.
        return $this->transactionWithSkCleanup($uploadedSkPath, function () use ($employee, $data, $request): DisciplineRecord {
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
     * Path hasil unggahan baru ikut dikembalikan supaya pemanggil bisa menghapusnya
     * sebagai kompensasi ketika transaksi database gagal (mencegah orphan file).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function storeSkUploadWithPath(array $data): array
    {
        $uploadedSkPath = null;

        if (($data['file_sk'] ?? null) instanceof UploadedFile) {
            $user = auth()->user();
            $canCreateDoc = $user === null || $user->hasPermission('dokumen_sk.create');
            // Di environment lokal dengan disable auth, bypass dianggap memiliki hak dokumen
            if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
                $canCreateDoc = true;
            }
            if (! $canCreateDoc) {
                // Permission dokumen tidak aktif: jangan simpan file, riwayat tetap dibuat tanpa berkas
                $data['file_sk'] = null;

                return [$data, null];
            }
            $data['file_sk'] = $this->files->storeSk($data['file_sk']);
            $uploadedSkPath = $data['file_sk'];
        }

        // String controlled path (reuse arsip) juga memerlukan dokumen_sk.create
        if (is_string($data['file_sk'] ?? null) && $data['file_sk'] !== '') {
            $user = auth()->user();
            $canCreateDoc = $user === null || $user->hasPermission('dokumen_sk.create');
            if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
                $canCreateDoc = true;
            }
            if (! $canCreateDoc) {
                $data['file_sk'] = null;
            }
        }

        return [$data, $uploadedSkPath];
    }

    /**
     * Menjalankan transaksi riwayat dengan kompensasi storage: karena file SK
     * diunggah sebelum transaksi, rollback wajib menghapus file baru tersebut
     * agar storage tidak menyimpan orphan file tanpa data riwayat.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function transactionWithSkCleanup(?string $uploadedSkPath, \Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (\Throwable $exception) {
            $this->files->deleteEmployeeDocumentFile($uploadedSkPath);

            throw $exception;
        }
    }
}
