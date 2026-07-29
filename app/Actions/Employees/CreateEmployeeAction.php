<?php

namespace App\Actions\Employees;

use App\Models\Document;
use App\Models\Employee;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use App\Services\Employees\TmtCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CreateEmployeeAction
{
    public function __construct(
        private readonly EmployeeFileStorageService $files,
        private readonly TmtCalculatorService $tmtCalculator,
    ) {}

    /**
     * Membuat pegawai baru, termasuk penyimpanan foto, dokumen SK,
     * riwayat pangkat, jabatan, KGB, dan pengangkatan awal, serta audit create.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data, Request $request): Employee
    {
        $data = $this->normalizeEmployeeContract($data);

        $uploadedFiles = [];
        try {
            return DB::transaction(function () use ($data, $request, &$uploadedFiles): Employee {
                if ($request->hasFile('foto')) {
                    $data['foto'] = $this->files->storePhoto($request->file('foto'));
                    $uploadedFiles[] = $data['foto'];
                }

                $employee = Employee::create($data);
                $sourceHistoryChanged = false;

                // 1. Pangkat (RankHistory)
                if ($request->filled('pangkat_golongan_id') || $request->filled('pangkat_no_sk') || $request->filled('pangkat_tmt_pangkat') || $request->hasFile('file_sk_pangkat')) {
                    $pangkatData = [
                        'golongan_id' => $data['pangkat_golongan_id'],
                        'no_sk' => $data['pangkat_no_sk'] ?? null,
                        'tanggal_sk' => $data['pangkat_tanggal_sk'] ?? null,
                        'tmt_pangkat' => $data['pangkat_tmt_pangkat'] ?? null,
                        'is_latest' => ($data['pangkat_tmt_pangkat'] ?? null) !== null,
                    ];

                    if ($request->hasFile('file_sk_pangkat') && $request->file('file_sk_pangkat')->isValid()) {
                        $file = $request->file('file_sk_pangkat');
                        $pangkatData['file_sk'] = $file->store('ranks/sk', 'public');
                        $uploadedFiles[] = $pangkatData['file_sk'];

                        $golonganLabel = isset($pangkatData['golongan_id'])
                            ? (RefGolongan::find($pangkatData['golongan_id'])?->kode ?? 'Pangkat Baru')
                            : 'Pangkat Baru';
                        Document::create([
                            'employee_id' => $employee->id,
                            'jenis_dokumen' => 'sk_pangkat',
                            'nama_dokumen' => 'SK Kenaikan Pangkat '.$golonganLabel,
                            'nomor_dokumen' => $pangkatData['no_sk'] ?? null,
                            'tanggal_dokumen' => $pangkatData['tanggal_sk'] ?? null,
                            'file_path' => $pangkatData['file_sk'],
                            'keterangan' => 'Diunggah otomatis saat tambah pegawai',
                        ]);
                    }

                    $employee->rankHistories()->create($pangkatData);
                    $sourceHistoryChanged = true;

                    $golongan = RefGolongan::find($data['pangkat_golongan_id']);
                    if ($golongan) {
                        $employee->update([
                            'golongan_terakhir' => $golongan->kode,
                            'pangkat_terakhir' => $golongan->nama,
                        ]);
                    }
                }

                // 2. Jabatan (PositionHistory)
                $hasJabatanReference = $request->filled('jabatan_jabatan_id') || $request->filled('jabatan_nama_jabatan');
                $hasJenisJabatan = $request->filled('jabatan_jabatan_id') || $request->filled('jabatan_jenis_jabatan_id');
                if ($hasJabatanReference || $hasJenisJabatan) {
                    $refJabatan = $request->filled('jabatan_jabatan_id')
                        ? RefJabatan::find($data['jabatan_jabatan_id'])
                        : null;
                    $namaJabatan = $refJabatan?->nama ?? $data['jabatan_nama_jabatan'] ?? null;
                    $jabatanData = [
                        'jabatan_id' => $data['jabatan_jabatan_id'] ?? null,
                        'nama_jabatan' => $namaJabatan,
                        'jenis_jabatan_id' => $data['jabatan_jenis_jabatan_id'] ?? $refJabatan?->jenis_jabatan_id,
                        'eselon_id' => $data['jabatan_eselon_id'] ?? null,
                        'unit_kerja_id' => $data['jabatan_unit_kerja_id'],
                        'kelas_jabatan' => $data['jabatan_kelas_jabatan'] ?? $employee->kelas_jabatan_terakhir,
                        'no_sk' => $data['jabatan_no_sk'],
                        'tanggal_sk' => $data['jabatan_tanggal_sk'],
                        'tmt_jabatan' => $data['jabatan_tmt_jabatan'],
                        'is_latest' => ($data['jabatan_tmt_jabatan'] ?? null) !== null,
                    ];

                    if ($request->hasFile('file_sk_jabatan') && $request->file('file_sk_jabatan')->isValid()) {
                        $file = $request->file('file_sk_jabatan');
                        $jabatanData['file_sk'] = $file->store('positions/sk', 'public');
                        $uploadedFiles[] = $jabatanData['file_sk'];

                        Document::create([
                            'employee_id' => $employee->id,
                            'jenis_dokumen' => 'sk_jabatan',
                            'nama_dokumen' => 'SK Jabatan '.($jabatanData['nama_jabatan'] ?? 'Baru'),
                            'nomor_dokumen' => $jabatanData['no_sk'] ?? null,
                            'tanggal_dokumen' => $jabatanData['tanggal_sk'] ?? null,
                            'file_path' => $jabatanData['file_sk'],
                            'keterangan' => 'Diunggah otomatis saat tambah pegawai',
                        ]);
                    }

                    $employee->positionHistories()->create($jabatanData);
                    $sourceHistoryChanged = true;

                    $employee->update([
                        'jabatan_terakhir' => $namaJabatan,
                        'kelas_jabatan_terakhir' => $jabatanData['kelas_jabatan'],
                        'kelas_jabatan' => $jabatanData['kelas_jabatan'],
                    ]);
                }

                // 3. KGB (SalaryHistory)
                if ($request->filled('kgb_gaji_pokok') || $request->filled('kgb_no_sk') || $request->filled('kgb_tmt_kgb') || $request->hasFile('file_sk_kgb')) {
                    $kgbData = [
                        'gaji_pokok' => $data['kgb_gaji_pokok'] ?? null,
                        'no_sk' => $data['kgb_no_sk'] ?? null,
                        'tanggal_sk' => $data['kgb_tanggal_sk'] ?? null,
                        'tmt_kgb' => $data['kgb_tmt_kgb'] ?? null,
                        'is_latest' => ($data['kgb_tmt_kgb'] ?? null) !== null,
                    ];

                    if ($request->hasFile('file_sk_kgb') && $request->file('file_sk_kgb')->isValid()) {
                        $file = $request->file('file_sk_kgb');
                        $kgbData['file_sk'] = $file->store('salaries/sk', 'public');
                        $uploadedFiles[] = $kgbData['file_sk'];

                        Document::create([
                            'employee_id' => $employee->id,
                            'jenis_dokumen' => 'sk_kgb',
                            'nama_dokumen' => 'SK KGB',
                            'nomor_dokumen' => $kgbData['no_sk'] ?? null,
                            'tanggal_dokumen' => $kgbData['tanggal_sk'] ?? null,
                            'file_path' => $kgbData['file_sk'],
                            'keterangan' => 'Diunggah otomatis saat tambah pegawai',
                        ]);
                    }

                    $employee->salaryHistories()->create($kgbData);
                    $sourceHistoryChanged = true;
                }

                // Sinkronisasi ditunda sampai seluruh riwayat sumber tersimpan agar snapshot tidak membaca keadaan parsial.
                if ($sourceHistoryChanged) {
                    $this->tmtCalculator->syncForEmployee($employee);
                }

                // 4. Pengangkatan (Appointment)
                if ($request->filled('pengangkatan_jenis_pengangkatan') || $request->filled('pengangkatan_no_sk') || $request->filled('pengangkatan_tmt_pengangkatan') || $request->hasFile('file_sk_pengangkatan')) {
                    $appointmentData = [
                        'jenis_pengangkatan' => $data['pengangkatan_jenis_pengangkatan'],
                        'tmt_pengangkatan' => $data['pengangkatan_tmt_pengangkatan'] ?? null,
                        'no_sk' => $data['pengangkatan_no_sk'] ?? null,
                        'tanggal_sk' => $data['pengangkatan_tanggal_sk'] ?? null,
                    ];

                    if ($request->hasFile('file_sk_pengangkatan') && $request->file('file_sk_pengangkatan')->isValid()) {
                        $file = $request->file('file_sk_pengangkatan');
                        $appointmentData['file_sk'] = $file->store('appointments/sk', 'public');
                        $uploadedFiles[] = $appointmentData['file_sk'];

                        Document::create([
                            'employee_id' => $employee->id,
                            'jenis_dokumen' => 'sk_pengangkatan',
                            'nama_dokumen' => 'SK Pengangkatan '.($appointmentData['jenis_pengangkatan'] ?? ''),
                            'nomor_dokumen' => $appointmentData['no_sk'] ?? null,
                            'tanggal_dokumen' => $appointmentData['tanggal_sk'] ?? null,
                            'file_path' => $appointmentData['file_sk'],
                            'keterangan' => 'Diunggah otomatis saat tambah pegawai',
                        ]);
                    }

                    $employee->appointment()->create($appointmentData);

                    $jenisPegawai = RefJenisPegawai::whereRaw('UPPER(nama) = ?', [
                        strtoupper($data['pengangkatan_jenis_pengangkatan']),
                    ])->first();
                    if ($jenisPegawai) {
                        $employee->update(['jenis_pegawai_id' => $jenisPegawai->id]);
                    }
                }

                $employee->refresh();
                AuditService::log('CREATE', 'Employee', $employee->id, null, $employee->getRawOriginal(), $request);

                return $employee;
            });
        } catch (\Throwable $e) {
            foreach ($uploadedFiles as $file) {
                Storage::disk('public')->delete($file);
            }
            throw $e;
        }
    }

    private function normalizeEmployeeContract(array $data): array
    {
        $email = $data['email_pribadi'] ?? $data['email'] ?? null;
        if ($email !== null) {
            $data['email_pribadi'] = $email;
            $data['email'] = $email;
        }

        $kelasJabatan = $data['kelas_jabatan_terakhir'] ?? $data['kelas_jabatan'] ?? null;
        if ($kelasJabatan !== null) {
            $data['kelas_jabatan_terakhir'] = $kelasJabatan;
            $data['kelas_jabatan'] = $kelasJabatan;
        }

        if (! empty($data['jabatan_id']) && empty($data['jabatan_terakhir'])) {
            $data['jabatan_terakhir'] = RefJabatan::find($data['jabatan_id'])?->nama;
        }

        if (empty($data['status_pegawai_id'])) {
            $statusName = $data['status_aktif'] ?? 'Aktif';
            $data['status_pegawai_id'] = RefStatusPegawai::where('nama', $statusName)->value('id')
                ?? RefStatusPegawai::where('is_default', true)->value('id');
        }

        if (! empty($data['status_pegawai_id']) && empty($data['status_aktif'])) {
            $data['status_aktif'] = RefStatusPegawai::whereKey($data['status_pegawai_id'])->value('nama') ?? 'Aktif';
        }

        return $data;
    }
}
