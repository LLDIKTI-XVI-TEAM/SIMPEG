<?php

namespace App\Actions\Employees;

use App\Models\Document;
use App\Models\Employee;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefProgramStudi;
use App\Models\RefStatusPegawai;
use App\Services\AuditService;
use App\Services\EmployeeFileStorageService;
use App\Services\Employees\TmtCalculatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CreateEmployeeAction
{
    /** @var list<string> */
    private const LIFECYCLE_FIELDS = [
        'status_aktif',
        'status_pegawai_id',
        'status_keterangan',
        'status_note',
        'status_tanggal',
        'status_berkas_path',
        'status_nomor_berkas',
    ];

    /** @var list<string> */
    public array $warnings = [];

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
        $this->warnings = [];
        $this->assertNoLifecycleFields($data);
        $data = $this->normalizeEmployeeContract($data);

        $uploadedFiles = [];
        try {
            return DB::transaction(function () use ($data, $request, &$uploadedFiles): Employee {
                if ($request->hasFile('foto')) {
                    $data['foto'] = $this->files->storePhoto($request->file('foto'));
                    $uploadedFiles[] = ['public', $data['foto']];
                }

                // Pegawai baru selalu lahir pada status kanonis AKTIF. Tanggal efektif,
                // histori, dan dokumen status hanya dibuat oleh workflow lifecycle setelah create.
                $activeStatus = RefStatusPegawai::query()
                    ->where('kode', 'AKTIF')
                    ->where('is_active', true)
                    ->first();

                if ($activeStatus === null || ! RefStatusPegawai::isActiveGroup($activeStatus->kelompok)) {
                    throw ValidationException::withMessages([
                        'status_pegawai_id' => 'Status awal AKTIF tidak tersedia atau tidak valid.',
                    ]);
                }

                $data['status_pegawai_id'] = $activeStatus->id;
                $data['status_aktif'] = $activeStatus->nama;

                $employee = Employee::create($data);
                $sourceHistoryChanged = false;
                $hasAuthoritativePensionDate = array_key_exists('tanggal_pensiun', $data)
                    && $data['tanggal_pensiun'] !== null
                    && $data['tanggal_pensiun'] !== '';

                // 1. Pangkat (RankHistory)
                $wantsPangkat = $request->filled('pangkat_golongan_id') || $request->filled('pangkat_no_sk') || $request->filled('pangkat_tmt_pangkat') || $request->hasFile('file_sk_pangkat');
                if ($wantsPangkat) {
                    $canHistory = $request->user()?->hasPermission('employee_histories.create');
                    if (! $canHistory) {
                        $this->warnings[] = 'Riwayat kepangkatan tidak dibuat: butuh permission employee_histories.create.';
                    } else {
                        $pangkatData = [
                            'golongan_id' => $data['pangkat_golongan_id'],
                            'no_sk' => $data['pangkat_no_sk'] ?? null,
                            'tanggal_sk' => $data['pangkat_tanggal_sk'] ?? null,
                            'tmt_pangkat' => $data['pangkat_tmt_pangkat'] ?? null,
                            'is_latest' => ($data['pangkat_tmt_pangkat'] ?? null) !== null,
                        ];

                        $canDoc = $request->user()?->hasPermission('dokumen_sk.create');
                        if ($request->hasFile('file_sk_pangkat') && $request->file('file_sk_pangkat')->isValid()) {
                            if (! $canDoc) {
                                $this->warnings[] = 'Berkas SK kepangkatan tidak diunggah: butuh permission dokumen_sk.create.';
                            } else {
                                $file = $request->file('file_sk_pangkat');
                                $pangkatData['file_sk'] = $this->files->storeEmployeeDocument($file, 'ranks/sk');
                                $uploadedFiles[] = [Document::STORAGE_DISK, $pangkatData['file_sk']];

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
                }

                // 2. Jabatan (PositionHistory)
                $hasJabatanReference = $request->filled('jabatan_jabatan_id') || $request->filled('jabatan_nama_jabatan');
                $hasJenisJabatan = $request->filled('jabatan_jabatan_id') || $request->filled('jabatan_jenis_jabatan_id');
                $wantsJabatan = $hasJabatanReference || $hasJenisJabatan;
                if ($wantsJabatan) {
                    $canHistory = $request->user()?->hasPermission('employee_histories.create');
                    if (! $canHistory) {
                        $this->warnings[] = 'Riwayat jabatan tidak dibuat: butuh permission employee_histories.create.';
                    } else {
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

                        $canDoc = $request->user()?->hasPermission('dokumen_sk.create');
                        if ($request->hasFile('file_sk_jabatan') && $request->file('file_sk_jabatan')->isValid()) {
                            if (! $canDoc) {
                                $this->warnings[] = 'Berkas SK jabatan tidak diunggah: butuh permission dokumen_sk.create.';
                            } else {
                                $file = $request->file('file_sk_jabatan');
                                $jabatanData['file_sk'] = $this->files->storeEmployeeDocument($file, 'positions/sk');
                                $uploadedFiles[] = [Document::STORAGE_DISK, $jabatanData['file_sk']];

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
                        }

                        $employee->positionHistories()->create($jabatanData);
                        $sourceHistoryChanged = true;

                        $employee->update([
                            'jabatan_terakhir' => $namaJabatan,
                            'kelas_jabatan_terakhir' => $jabatanData['kelas_jabatan'],
                            'kelas_jabatan' => $jabatanData['kelas_jabatan'],
                        ]);
                    }
                }

                // 3. KGB (SalaryHistory)
                $wantsKgb = $request->filled('kgb_gaji_pokok') || $request->filled('kgb_no_sk') || $request->filled('kgb_tmt_kgb') || $request->hasFile('file_sk_kgb');
                if ($wantsKgb) {
                    $canHistory = $request->user()?->hasPermission('employee_histories.create');
                    if (! $canHistory) {
                        $this->warnings[] = 'Riwayat KGB tidak dibuat: butuh permission employee_histories.create.';
                    } else {
                        $kgbData = [
                            'gaji_pokok' => $data['kgb_gaji_pokok'] ?? null,
                            'no_sk' => $data['kgb_no_sk'] ?? null,
                            'tanggal_sk' => $data['kgb_tanggal_sk'] ?? null,
                            'tmt_kgb' => $data['kgb_tmt_kgb'] ?? null,
                            'is_latest' => ($data['kgb_tmt_kgb'] ?? null) !== null,
                        ];

                        $canDoc = $request->user()?->hasPermission('dokumen_sk.create');
                        if ($request->hasFile('file_sk_kgb') && $request->file('file_sk_kgb')->isValid()) {
                            if (! $canDoc) {
                                $this->warnings[] = 'Berkas SK KGB tidak diunggah: butuh permission dokumen_sk.create.';
                            } else {
                                $file = $request->file('file_sk_kgb');
                                $kgbData['file_sk'] = $this->files->storeEmployeeDocument($file, 'salaries/sk');
                                $uploadedFiles[] = [Document::STORAGE_DISK, $kgbData['file_sk']];

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
                        }

                        $employee->salaryHistories()->create($kgbData);
                        $sourceHistoryChanged = true;
                    }
                }

                // 4. Pengangkatan (Appointment)
                $wantsPengangkatan = $request->filled('pengangkatan_jenis_pengangkatan') || $request->filled('pengangkatan_no_sk') || $request->filled('pengangkatan_tmt_pengangkatan') || $request->hasFile('file_sk_pengangkatan');
                if ($wantsPengangkatan) {
                    $canHistory = $request->user()?->hasPermission('employee_histories.create');
                    if (! $canHistory) {
                        $this->warnings[] = 'Riwayat pengangkatan tidak dibuat: butuh permission employee_histories.create.';
                    } else {
                        $appointmentData = [
                            'jenis_pengangkatan' => $data['pengangkatan_jenis_pengangkatan'],
                            'tmt_pengangkatan' => $data['pengangkatan_tmt_pengangkatan'] ?? null,
                            'no_sk' => $data['pengangkatan_no_sk'] ?? null,
                            'tanggal_sk' => $data['pengangkatan_tanggal_sk'] ?? null,
                        ];

                        $canDoc = $request->user()?->hasPermission('dokumen_sk.create');
                        if ($request->hasFile('file_sk_pengangkatan') && $request->file('file_sk_pengangkatan')->isValid()) {
                            if (! $canDoc) {
                                $this->warnings[] = 'Berkas SK pengangkatan tidak diunggah: butuh permission dokumen_sk.create.';
                            } else {
                                $file = $request->file('file_sk_pengangkatan');
                                $appointmentData['file_sk'] = $this->files->storeEmployeeDocument($file, 'appointments/sk');
                                $uploadedFiles[] = [Document::STORAGE_DISK, $appointmentData['file_sk']];

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
                        }

                        $employee->appointment()->create($appointmentData);
                        $sourceHistoryChanged = true;

                        $jenisPegawai = RefJenisPegawai::whereRaw('UPPER(nama) = ?', [
                            strtoupper($data['pengangkatan_jenis_pengangkatan']),
                        ])->first();
                        if ($jenisPegawai) {
                            $employee->update(['jenis_pegawai_id' => $jenisPegawai->id]);
                        }
                    }
                }

                // Sinkronisasi tunggal setelah seluruh riwayat sumber tersimpan mencegah kalkulasi memakai state parsial.
                if ($hasAuthoritativePensionDate) {
                    // Hint authoritative menjaga tanggal resmi dari form agar tidak ditimpa kalkulasi BUP.
                    $this->tmtCalculator->syncForEmployee($employee, true);
                } elseif ($sourceHistoryChanged) {
                    $this->tmtCalculator->syncForEmployee($employee);
                }

                // 5. Berkas Lainnya (KTP, KK, SK Mutasi, SK Pensiun, atau jenis manual)
                $wantsBerkas = $request->filled('berkas_lainnya_jenis')
                    && $request->hasFile('file_berkas_lainnya')
                    && $request->file('file_berkas_lainnya')->isValid();
                if ($wantsBerkas) {
                    $canDoc = $request->user()?->hasPermission('dokumen_sk.create');
                    if (! $canDoc) {
                        $this->warnings[] = 'Berkas lainnya tidak diunggah: butuh permission dokumen_sk.create.';
                    } else {
                        $jenis = $data['berkas_lainnya_jenis'];
                        $jenisEfektif = $jenis === 'Lainnya'
                            ? trim((string) ($data['berkas_lainnya_jenis_manual'] ?? ''))
                            : $jenis;

                        // Hanya KTP/KK dan dokumen tambahan yang masuk kategori umum.
                        // SK Mutasi/Pensiun memiliki kategori khusus karena menjadi dasar status pegawai.
                        $kategori = match ($jenis) {
                            'KTP', 'KK' => 'ktp_kk',
                            'SK Mutasi' => 'sk_mutasi',
                            'SK Pensiun' => 'sk_pensiun',
                            default => 'lainnya',
                        };

                        $filePath = $this->files->storeBerkasLainnya($request->file('file_berkas_lainnya'), $employee->id);
                        $uploadedFiles[] = [Document::STORAGE_DISK, $filePath];

                        Document::create([
                            'employee_id' => $employee->id,
                            'jenis_dokumen' => $kategori,
                            'nama_dokumen' => $jenisEfektif,
                            'nomor_dokumen' => $data['berkas_lainnya_nomor'] ?? null,
                            'tanggal_dokumen' => $data['berkas_lainnya_tanggal'] ?? null,
                            'file_path' => $filePath,
                            'keterangan' => $data['berkas_lainnya_deskripsi'] ?? null,
                        ]);
                    }
                } elseif ($request->filled('berkas_lainnya_jenis')) {
                    // Jenis terisi tapi file tidak ada atau tidak valid — tetap warning jika tanpa permission file
                    $canDoc = $request->user()?->hasPermission('dokumen_sk.create');
                    if (! $canDoc && $request->hasFile('file_berkas_lainnya')) {
                        $this->warnings[] = 'Berkas lainnya tidak diunggah: butuh permission dokumen_sk.create.';
                    }
                }

                $employee->refresh();
                AuditService::log('CREATE', 'Employee', $employee->id, null, $employee->getRawOriginal(), $request);

                return $employee;
            });
        } catch (\Throwable $e) {
            foreach ($uploadedFiles as [$disk, $file]) {
                Storage::disk($disk)->delete($file);
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

        if (! empty($data['program_studi_id'])) {
            $data['prodi_pendidikan_terakhir'] = RefProgramStudi::find($data['program_studi_id'])?->nama;
        } else {
            unset($data['program_studi_id'], $data['prodi_pendidikan_terakhir']);
        }

        unset($data['clear_program_studi']);

        return $data;
    }

    /** Caller non-HTTP juga tidak boleh menulis snapshot lifecycle melalui create. */
    private function assertNoLifecycleFields(array $data): void
    {
        $errors = [];

        foreach (self::LIFECYCLE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $errors[$field] = 'Field lifecycle tidak dapat diisi saat membuat pegawai.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
