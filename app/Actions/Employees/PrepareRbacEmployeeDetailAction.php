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

class PrepareRbacEmployeeDetailAction
{
    public function __construct(
        private readonly EmployeeHistoryAttachmentService $attachments,
        private readonly EmployeeDocumentStatusService $documentStatusService,
        private readonly PrepareEmployeeDocumentRowsAction $prepareDocuments,
    ) {}

    /**
     * Menyiapkan detail pegawai canonical RBAC dengan granular permission gate.
     * Data tambahan (options, supervisor, dll.) disiapkan agar surface RBAC memiliki aksi yang sama dengan dashboard.
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

        // NIK keluarga hanya disertakan untuk role pengelola HR; role lain
        // (termasuk Pimpinan) menerima payload tanpa NIK agar plaintext tidak bocor.
        $familyColumns = [
            'id',
            'employee_id',
            'nama_anggota',
            'hubungan',
            'tempat_lahir',
            'tanggal_lahir',
            'jenis_kelamin',
            'status_tunjangan',
            'pekerjaan',
        ];
        if (in_array($viewer->getEffectiveRole(), ['super_admin', 'admin_kepegawaian'], true)) {
            $familyColumns[] = 'nik';
        }

        $employee = Employee::query()
            // NIK dan No KK tetap tidak diambil di canonical surface agar plaintext tidak bocor; surface super_admin mentah tetap di /pegawai/{id}
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
                    ->when(! $canReadHistories, fn ($q) => $q->whereRaw('1 = 0'))
                    ->select(['id', 'employee_id', 'status_nama', 'keterangan', 'tanggal_efektif', 'nomor_berkas', 'file_sk', 'is_latest', 'created_at'])
                    ->orderByDesc('is_latest')->orderByDesc('tanggal_efektif')->orderByDesc('created_at')->orderBy('id'),
                'appointment' => fn ($query) => $query->when(! $canReadHistories, fn ($q) => $q->whereRaw('1 = 0')),
                'appointments' => fn ($query) => $query->when(! $canReadHistories, fn ($q) => $q->whereRaw('1 = 0'))->orderByDesc('tmt_pengangkatan'),
                'rankHistories' => fn ($query) => $query->when(! $canReadHistories, fn ($q) => $q->whereRaw('1 = 0'))->with('golongan:id,kode,nama')->orderByDesc('tmt_pangkat'),
                'positionHistories' => fn ($query) => $query->when(! $canReadHistories, fn ($q) => $q->whereRaw('1 = 0'))->with(['jabatan:id,nama', 'unitKerja:id,nama'])->orderByDesc('is_latest')->orderByDesc('tmt_jabatan'),
                'salaryHistories' => fn ($query) => $query->when(! $canReadHistories, fn ($q) => $q->whereRaw('1 = 0'))->orderByDesc('tmt_kgb'),
                'disciplineRecords' => fn ($query) => $query->when(! $canReadDiscipline, fn ($q) => $q->whereRaw('1 = 0'))->orderByDesc('tanggal_mulai'),
                'educationHistories' => fn ($query) => $query->when(! $canReadHistories, fn ($q) => $q->whereRaw('1 = 0'))->with(['jenjang:id,nama,urutan', 'programStudi:id,nama'])->orderByDesc('tahun_lulus'),
                'documents' => fn ($query) => $query
                    ->when(! $canReadDocuments, fn ($q) => $q->whereRaw('1 = 0'))
                    ->when($viewer->getEffectiveRole() === 'pimpinan', fn ($q) => $q->whereIn('jenis_dokumen', DocumentCategory::visibleToPimpinanKeys()))
                    ->orderByDesc('tanggal_dokumen'),
                'families' => fn ($query) => $query
                    ->when(! $canReadFamilies, fn ($q) => $q->whereRaw('1 = 0'))
                    ->select($familyColumns)
                    ->orderBy('hubungan')->orderBy('nama_anggota'),
                'supervisorAssignments' => fn ($query) => $query
                    ->select(['id', 'employee_id', 'supervisor_id', 'kepala_bagian_id', 'tanggal_mulai', 'tanggal_berakhir'])
                    ->whereDate('tanggal_mulai', '<=', today())
                    ->where(function ($q): void {
                        $q->whereNull('tanggal_berakhir')->orWhereDate('tanggal_berakhir', '>=', today());
                    })
                    ->with(['supervisor:id,nama_lengkap,nip,jabatan_terakhir', 'supervisor.positionHistories' => fn ($p) => $p->select(['id', 'employee_id', 'unit_kerja_id', 'nama_jabatan', 'is_latest'])->where('is_latest', true)->with('unitKerja:id,nama')]),
            ])
            ->findOrFail($employeeId);

        $employee->loadMissing(['programStudi', 'educationHistories.programStudi']);

        $this->prepareAttachmentDownloadUrls($employee, $canReadHistories, $canReadDiscipline, $canReadDocuments, $viewer);
        $latestStatusHistory = $employee->statusHistories->firstWhere('is_latest', true) ?? $employee->statusHistories->first();
        $activePosition = $employee->positionHistories->firstWhere('is_latest', true);
        $latestRank = $employee->rankHistories->firstWhere('is_latest', true);

        // Options untuk modal tambah/edit (sama seperti dashboard)
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
        $latestPosition = $employee->positionHistories->firstWhere('is_latest', true);
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

    private function prepareAttachmentDownloadUrls(Employee $employee, bool $canReadHistories, bool $canReadDiscipline, bool $canReadDocuments, User $viewer): void
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

        if ($canReadHistories) {
            foreach (['rank' => $employee->rankHistories, 'position' => $employee->positionHistories, 'salary' => $employee->salaryHistories, 'education' => $employee->educationHistories] as $type => $histories) {
                $histories->each(function (Model $history) use ($employee, $type): void {
                    $history->setAttribute('rbac_attachment_download_url', $this->attachments->downloadUrl($employee, $type, $history, 'rbac.pegawai.history-attachments.download'));
                });
            }
            if ($employee->appointment !== null) {
                $employee->appointment->setAttribute('rbac_attachment_download_url', $this->attachments->downloadUrl($employee, 'appointment', $employee->appointment, 'rbac.pegawai.history-attachments.download'));
            }
            $employee->statusHistories->each(function (EmployeeStatusHistory $history) use ($employee): void {
                $history->setAttribute('rbac_attachment_download_url', $this->attachments->statusDownloadUrl($employee, $history, 'rbac.pegawai.status-attachments.download', false));
            });
            $employee->setAttribute('rbac_status_attachment_download_url', $this->attachments->statusSnapshotDownloadUrl($employee, 'rbac.pegawai.status-attachments.download', false));
        }

        if ($canReadDiscipline) {
            $employee->disciplineRecords->each(function (Model $history) use ($employee): void {
                $history->setAttribute('rbac_attachment_download_url', $this->attachments->downloadUrl($employee, 'discipline', $history, 'rbac.pegawai.discipline-attachments.download', false));
            });
        }

        if ($canReadDocuments) {
            $allowedKeys = $viewer->getEffectiveRole() === 'pimpinan' ? DocumentCategory::visibleToPimpinanKeys() : DocumentCategory::keys();
            $employee->documents->each(function (Document $document) use ($employee, $allowedKeys): void {
                $fileAvailable = $this->attachments->availableDocumentPath($employee, $document, $allowedKeys) !== null;
                $document->setAttribute('rbac_download_url', $fileAvailable ? route('rbac.pegawai.documents.download', ['employee' => $employee, 'document' => $document]) : null);
                $document->setAttribute('rbac_file_size_label', $fileAvailable ? $document->fileSizeLabel() : 'File tidak ditemukan');
            });
        }
    }
}
