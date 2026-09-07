<?php

namespace App\Actions\Employees;

use App\Actions\Documents\PrepareEmployeeDocumentRowsAction;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenjangPendidikan;
use App\Models\RefProgramStudi;
use App\Models\RefUnitKerja;
use App\Models\User;
use App\Services\EmployeeDocumentStatusService;
use App\Services\Employees\EmployeeHistoryAttachmentService;
use App\Support\Documents\DocumentCategory;
use App\Support\Employees\EmployeeProfilePresentation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PreparePimpinanEmployeeDetailAction
{
    public function __construct(
        private readonly EmployeeHistoryAttachmentService $attachments,
        private readonly EmployeeDocumentStatusService $documentStatusService,
        private readonly PrepareEmployeeDocumentRowsAction $prepareDocuments,
    ) {}

    /**
     * Menyiapkan seluruh data detail pegawai yang boleh dibaca Pimpinan dengan opsi aksi yang sama seperti dashboard.
     *
     * @return array<string, mixed>
     */
    public function execute(string $employeeId, User $viewer): array
    {
        $canReadFamilies = $viewer->hasPermission('employee_families.read');
        $canReadHistories = $viewer->hasPermission('employee_histories.read');
        $canReadDiscipline = $viewer->hasPermission('discipline_records.read');
        $canReadDocuments = $viewer->hasPermission('dokumen_sk.read');
        $canCreateFamily = $viewer->hasPermission('employee_families.create');
        $canDeleteFamily = $viewer->hasPermission('employee_families.delete');
        $canCreateDiscipline = $viewer->hasPermission('discipline_records.create');
        $canCreateEmployeeHistory = $viewer->hasPermission('employee_histories.create');
        $canUpdateEmployeeHistory = $viewer->hasPermission('employee_histories.update') || $viewer->hasPermission('employee_histories.create');
        $canCreateDocument = $viewer->hasPermission('dokumen_sk.create');
        $canUpdateDocument = $viewer->hasPermission('dokumen_sk.update');
        $canDeleteDocument = $viewer->hasPermission('dokumen_sk.delete');
        $canUpdateEmployee = $viewer->hasPermission('employees.update');
        $canDeactivateEmployee = $viewer->hasPermission('employees.deactivate');
        $canRestoreEmployee = $viewer->hasPermission('employees.restore');

        $employee = Employee::query()
            // Kolom NIK dan No. KK tidak diambil agar plaintext hasil dekripsi tidak pernah masuk payload view Pimpinan.
            ->select([
                'id',
                'nama_lengkap',
                'nama_dengan_gelar',
                'nip',
                'foto',
                'tempat_lahir',
                'tanggal_lahir',
                'jenis_kelamin',
                'agama_id',
                'status_kawin_id',
                'golongan_darah',
                'jenis_pegawai_id',
                'status_pegawai_id',
                'status_aktif',
                'status_keterangan',
                'status_tanggal',
                'is_kinerja_baik',
                'is_kepala_lembaga',
                'jabatan_terakhir',
                'golongan_terakhir',
                'kelas_jabatan',
                'kelas_jabatan_terakhir',
                'pendidikan_terakhir',
                'prodi_pendidikan_terakhir',
                'program_studi_id',
                'email',
                'email_pribadi',
                'no_hp',
                'no_telepon_rumah',
                'alamat',
                'tanggal_pensiun',
                'tanggal_kenaikan_pangkat_berikutnya',
                'tanggal_kgb_berikutnya',
                'pangkat_terakhir',
                'status_berkas_path',
                'status_nomor_berkas',
                'is_satyalancana_eligible',
                'satyalancana_note',
            ])
            ->with([
                'agama:id,nama',
                'statusKawin:id,nama',
                'jenisPegawai:id,nama',
                'statusPegawai:id,kode,nama,kelompok',
                'programStudi:id,nama',
                'statusHistories' => fn ($query) => $query
                    ->when(! $canReadHistories, fn ($query) => $query->whereRaw('1 = 0'))
                    ->select([
                        'id',
                        'employee_id',
                        'status_nama',
                        'keterangan',
                        'tanggal_efektif',
                        'nomor_berkas',
                        'file_sk',
                        'is_latest',
                        'created_at',
                    ])
                    ->orderByDesc('is_latest')
                    ->orderByDesc('tanggal_efektif')
                    ->orderByDesc('created_at')
                    ->orderBy('id'),
                'appointment' => fn ($query) => $query
                    ->when(! $canReadHistories, fn ($query) => $query->whereRaw('1 = 0')),
                'rankHistories' => fn ($query) => $query
                    ->when(! $canReadHistories, fn ($query) => $query->whereRaw('1 = 0'))
                    ->orderByDesc('tmt_pangkat'),
                'positionHistories' => fn ($query) => $query
                    ->when(! $canReadHistories, fn ($query) => $query->whereRaw('1 = 0'))
                    ->orderByDesc('is_latest')
                    ->orderByDesc('tmt_jabatan'),
                'salaryHistories' => fn ($query) => $query
                    ->when(! $canReadHistories, fn ($query) => $query->whereRaw('1 = 0'))
                    ->orderByDesc('tmt_kgb'),
                'disciplineRecords' => fn ($query) => $query
                    ->when(! $canReadDiscipline, fn ($query) => $query->whereRaw('1 = 0'))
                    ->orderByDesc('tanggal_mulai'),
                'educationHistories' => fn ($query) => $query
                    ->when(! $canReadHistories, fn ($query) => $query->whereRaw('1 = 0'))
                    ->with(['jenjang:id,nama,urutan', 'programStudi:id,nama'])
                    ->orderByDesc('tahun_lulus'),
                'documents' => fn ($query) => $query
                    ->when(! $canReadDocuments, fn ($query) => $query->whereRaw('1 = 0'))
                    // KTP/KK tidak masuk payload karena metadata dan file-nya memuat identitas sensitif.
                    ->whereIn('jenis_dokumen', DocumentCategory::visibleToPimpinanKeys())
                    ->orderByDesc('tanggal_dokumen'),
                'families' => fn ($query) => $query
                    ->when(! $canReadFamilies, fn ($query) => $query->whereRaw('1 = 0'))
                    // NIK keluarga sengaja tidak dipilih agar jalur baca Pimpinan fail-closed.
                    ->select([
                        'id',
                        'employee_id',
                        'nama_anggota',
                        'hubungan',
                        'tempat_lahir',
                        'tanggal_lahir',
                        'jenis_kelamin',
                        'status_tunjangan',
                        'pekerjaan',
                    ])
                    ->orderBy('hubungan')
                    ->orderBy('nama_anggota'),
                'supervisorAssignments' => fn ($query) => $query
                    ->select(['id', 'employee_id', 'supervisor_id', 'kepala_bagian_id', 'tanggal_mulai', 'tanggal_berakhir'])
                    // Assignment hanya aktif setelah tanggal mulai dan tanggal akhir bersifat inklusif.
                    ->whereDate('tanggal_mulai', '<=', today())
                    ->where(function ($query): void {
                        $query->whereNull('tanggal_berakhir')
                            ->orWhereDate('tanggal_berakhir', '>=', today());
                    })
                    ->with([
                        'supervisor:id,nama_lengkap,nip,jabatan_terakhir',
                        'supervisor.positionHistories' => fn ($positions) => $positions
                            ->select(['id', 'employee_id', 'unit_kerja_id', 'nama_jabatan', 'is_latest'])
                            ->where('is_latest', true)
                            ->with('unitKerja:id,nama'),
                    ]),
            ])
            ->findOrFail($employeeId);

        // Relasi referensi dimuat di action agar surface Pimpinan tetap read-only dan tidak memicu query dari Blade.
        $employee->loadMissing(['programStudi', 'educationHistories.programStudi']);

        $this->prepareAttachmentDownloadUrls($employee);
        $latestStatusHistory = $employee->statusHistories->firstWhere('is_latest', true)
            ?? $employee->statusHistories->first();
        $activePosition = $employee->positionHistories->firstWhere('is_latest', true);
        $latestRank = $employee->rankHistories->firstWhere('is_latest', true);
        $latestPosition = $employee->positionHistories->firstWhere('is_latest', true);
        $golonganOptions = RefGolongan::all();
        $jabatanOptions = RefJabatan::with('jenisJabatan')->orderBy('nama')->get();
        $jenisJabatanOptions = RefJenisJabatan::all();
        $unitKerjaOptions = RefUnitKerja::all();
        $eselonOptions = RefEselon::all();
        $jenjangOptions = RefJenjangPendidikan::orderBy('urutan')->get();
        $programStudiOptions = RefProgramStudi::query()->where('is_active', true)->orderBy('nama')->get();
        $educationProgramStudiOptions = RefProgramStudi::query()
            ->where(function ($q) use ($employee): void {
                $q->where('is_active', true)->orWhereIn('id', $employee->educationHistories->pluck('program_studi_id')->filter());
            })->orderBy('nama')->get();
        $estimasiTanggalPensiun = EmployeeProfilePresentation::retirementDate($employee);
        $currentSupervisor = $employee->supervisorAssignments->filter(fn ($a): bool => $a->tanggal_mulai->lte(today()) && ($a->tanggal_berakhir === null || $a->tanggal_berakhir->gte(today())))->sortByDesc('tanggal_mulai')->first();
        $currentSupervisorPosition = $currentSupervisor?->supervisor?->positionHistories->where('is_latest', true)->sortByDesc('tmt_jabatan')->first();
        $oldSupervisorId = old('kepala_bagian_id');
        $selectedSupervisor = is_string($oldSupervisorId) && Str::isUuid($oldSupervisorId) ? Employee::query()->select(['id', 'nama_lengkap', 'nip'])->find($oldSupervisorId) : null;
        $selectedSupervisorId = $selectedSupervisor?->id ?? $currentSupervisor?->supervisor?->id;
        $selectedSupervisorName = $selectedSupervisor?->nama_lengkap ?? $currentSupervisor?->supervisor?->nama_lengkap;
        $documentRows = $this->prepareDocuments->execute($employee);
        $documentStatus = $this->documentStatusService->summarize($employee);
        $archivedSkRows = $documentRows['sk'];
        $otherDocumentRows = $documentRows['others'];
        $statusPresentation = EmployeeProfilePresentation::status($employee, $latestStatusHistory);
        $pendidikanCacheVersion = md5((string) (RefProgramStudi::max('updated_at') ?? '0').'|'.(string) ($employee->updated_at ?? '0').'|'.(string) ($employee->educationHistories->max('updated_at') ?? '0').'|'.(string) $employee->educationHistories->count());

        return [
            'p' => $employee,
            'statusPresentation' => $statusPresentation,
            'activePosition' => $activePosition,
            'latestRank' => $latestRank,
            'latestPosition' => $latestPosition,
            'latestStatusHistory' => $latestStatusHistory,
            'activeSupervisorAssignments' => $employee->supervisorAssignments,
            'retirementDate' => $estimasiTanggalPensiun,
            'canReadFamilies' => $canReadFamilies,
            'canReadHistories' => $canReadHistories,
            'canReadDiscipline' => $canReadDiscipline,
            'canReadDocuments' => $canReadDocuments,
            'canCreateFamily' => $canCreateFamily,
            'canDeleteFamily' => $canDeleteFamily,
            'canCreateDiscipline' => $canCreateDiscipline,
            'canCreateEmployeeHistory' => $canCreateEmployeeHistory,
            'canUpdateEmployeeHistory' => $canUpdateEmployeeHistory,
            'canCreateDocument' => $canCreateDocument,
            'canUpdateDocument' => $canUpdateDocument,
            'canDeleteDocument' => $canDeleteDocument,
            'canUpdateEmployee' => $canUpdateEmployee,
            'canDeactivateEmployee' => $canDeactivateEmployee && $employee->isActive(),
            'canRestoreEmployee' => $canRestoreEmployee && ! $employee->isActive(),
            'golonganOptions' => $golonganOptions,
            'jabatanOptions' => $jabatanOptions,
            'jenisJabatanOptions' => $jenisJabatanOptions,
            'unitKerjaOptions' => $unitKerjaOptions,
            'eselonOptions' => $eselonOptions,
            'jenjangOptions' => $jenjangOptions,
            'programStudiOptions' => $programStudiOptions,
            'educationProgramStudiOptions' => $educationProgramStudiOptions,
            'estimasiTanggalPensiun' => $estimasiTanggalPensiun,
            'currentSupervisor' => $currentSupervisor,
            'currentSupervisorPosition' => $currentSupervisorPosition,
            'selectedSupervisorId' => $selectedSupervisorId,
            'selectedSupervisorName' => $selectedSupervisorName,
            'documentStatus' => $documentStatus,
            'archivedSkRows' => $archivedSkRows,
            'otherDocumentRows' => $otherDocumentRows,
            'pendidikanCacheVersion' => $pendidikanCacheVersion,
        ];
    }

    /**
     * Menyiapkan URL hanya untuk attachment yang benar-benar tersedia di disk privat.
     *
     * Blade hanya membaca atribut presentasi ini sehingga tidak menjalankan pemeriksaan
     * storage berulang dan tidak menawarkan tautan mati kepada Pimpinan.
     */
    private function prepareAttachmentDownloadUrls(Employee $employee): void
    {
        $this->attachments->primeDocumentReferences(collect([
            ...$employee->rankHistories->pluck('file_sk'),
            ...$employee->positionHistories->pluck('file_sk'),
            ...$employee->salaryHistories->pluck('file_sk'),
            ...$employee->disciplineRecords->pluck('file_sk'),
            ...$employee->educationHistories->pluck('file_ijazah'),
            ...$employee->statusHistories->pluck('file_sk'),
            ...$employee->documents->pluck('file_path'),
            $employee->appointment?->file_sk,
            $employee->status_berkas_path,
        ]));

        foreach ([
            'rank' => $employee->rankHistories,
            'position' => $employee->positionHistories,
            'salary' => $employee->salaryHistories,
            'education' => $employee->educationHistories,
        ] as $type => $histories) {
            $histories->each(function (Model $history) use ($employee, $type): void {
                $history->setAttribute(
                    'pimpinan_attachment_download_url',
                    $this->availableHistoryUrl($employee, $type, $history),
                );
            });
        }

        if ($employee->appointment !== null) {
            $employee->appointment->setAttribute(
                'pimpinan_attachment_download_url',
                $this->availableHistoryUrl(
                    $employee,
                    'appointment',
                    $employee->appointment,
                ),
            );
        }

        $employee->disciplineRecords->each(function (Model $history) use ($employee): void {
            $history->setAttribute(
                'pimpinan_attachment_download_url',
                $this->attachments->downloadUrl(
                    $employee,
                    'discipline',
                    $history,
                    'pimpinan.pegawai.discipline-attachments.download',
                    false,
                ),
            );
        });

        $employee->documents->each(function (Document $document) use ($employee): void {
            $fileAvailable = $this->attachments->availableDocumentPath(
                $employee,
                $document,
                DocumentCategory::visibleToPimpinanKeys(),
            ) !== null;

            $document->setAttribute(
                'pimpinan_download_url',
                $fileAvailable
                    ? route('pimpinan.pegawai.documents.download', [
                        'employee' => $employee,
                        'document' => $document,
                    ])
                    : null,
            );
            $document->setAttribute(
                'pimpinan_file_size_label',
                $fileAvailable ? $document->fileSizeLabel() : 'File tidak ditemukan',
            );
        });

        $employee->statusHistories->each(function (EmployeeStatusHistory $history) use ($employee): void {
            $history->setAttribute(
                'pimpinan_attachment_download_url',
                $this->attachments->statusDownloadUrl(
                    $employee,
                    $history,
                    'pimpinan.pegawai.status-attachments.download',
                    false,
                ),
            );
        });

        $employee->setAttribute(
            'pimpinan_status_attachment_download_url',
            $this->attachments->statusSnapshotDownloadUrl(
                $employee,
                'pimpinan.pegawai.status-attachments.download',
                false,
            ),
        );
    }

    private function availableHistoryUrl(Employee $employee, string $type, Model $history): ?string
    {
        return $this->attachments->downloadUrl(
            $employee,
            $type,
            $history,
            'pimpinan.pegawai.history-attachments.download',
        );
    }
}
