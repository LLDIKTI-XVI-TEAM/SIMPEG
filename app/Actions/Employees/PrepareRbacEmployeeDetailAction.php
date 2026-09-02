<?php

namespace App\Actions\Employees;

use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\SupervisorAssignment;
use App\Models\User;
use App\Services\Employees\EmployeeHistoryAttachmentService;
use App\Support\Documents\DocumentCategory;
use App\Support\Employees\EmployeeProfilePresentation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PrepareRbacEmployeeDetailAction
{
    public function __construct(private readonly EmployeeHistoryAttachmentService $attachments) {}

    /**
     * Menyiapkan detail pegawai canonical RBAC dengan granular permission gate.
     *
     * @return array{p: Employee, statusPresentation: array{label: string, badge: string, dot: string, effectiveDate: Carbon|null}, activePosition: PositionHistory|null, latestRank: RankHistory|null, latestStatusHistory: EmployeeStatusHistory|null, activeSupervisorAssignments: Collection<int, SupervisorAssignment>, retirementDate: Carbon|null, canReadFamilies: bool, canReadHistories: bool, canReadDiscipline: bool, canReadDocuments: bool}
     */
    public function execute(string $employeeId, User $viewer): array
    {
        $canReadFamilies = $viewer->hasPermission('employee_families.read');
        $canReadHistories = $viewer->hasPermission('employee_histories.read');
        $canReadDiscipline = $viewer->hasPermission('discipline_records.read');
        $canReadDocuments = $viewer->hasPermission('dokumen_sk.read');

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
                'rankHistories' => fn ($query) => $query->when(! $canReadHistories, fn ($q) => $q->whereRaw('1 = 0'))->orderByDesc('tmt_pangkat'),
                'positionHistories' => fn ($query) => $query->when(! $canReadHistories, fn ($q) => $q->whereRaw('1 = 0'))->orderByDesc('is_latest')->orderByDesc('tmt_jabatan'),
                'salaryHistories' => fn ($query) => $query->when(! $canReadHistories, fn ($q) => $q->whereRaw('1 = 0'))->orderByDesc('tmt_kgb'),
                'disciplineRecords' => fn ($query) => $query->when(! $canReadDiscipline, fn ($q) => $q->whereRaw('1 = 0'))->orderByDesc('tanggal_mulai'),
                'educationHistories' => fn ($query) => $query->when(! $canReadHistories, fn ($q) => $q->whereRaw('1 = 0'))->with(['jenjang:id,nama,urutan', 'programStudi:id,nama'])->orderByDesc('tahun_lulus'),
                'documents' => fn ($query) => $query
                    ->when(! $canReadDocuments, fn ($q) => $q->whereRaw('1 = 0'))
                    ->orderByDesc('tanggal_dokumen'),
                'families' => fn ($query) => $query
                    ->when(! $canReadFamilies, fn ($q) => $q->whereRaw('1 = 0'))
                    ->select(['id', 'employee_id', 'nama_anggota', 'hubungan', 'tempat_lahir', 'tanggal_lahir', 'jenis_kelamin', 'status_tunjangan', 'pekerjaan'])
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

        $this->prepareAttachmentDownloadUrls($employee, $canReadHistories, $canReadDiscipline, $canReadDocuments);
        $latestStatusHistory = $employee->statusHistories->firstWhere('is_latest', true) ?? $employee->statusHistories->first();
        $activePosition = $employee->positionHistories->firstWhere('is_latest', true);
        $latestRank = $employee->rankHistories->firstWhere('is_latest', true);

        return [
            'p' => $employee,
            'statusPresentation' => EmployeeProfilePresentation::status($employee, $latestStatusHistory),
            'activePosition' => $activePosition,
            'latestRank' => $latestRank,
            'latestStatusHistory' => $latestStatusHistory,
            'activeSupervisorAssignments' => $employee->supervisorAssignments,
            'retirementDate' => EmployeeProfilePresentation::retirementDate($employee),
            'canReadFamilies' => $canReadFamilies,
            'canReadHistories' => $canReadHistories,
            'canReadDiscipline' => $canReadDiscipline,
            'canReadDocuments' => $canReadDocuments,
        ];
    }

    private function prepareAttachmentDownloadUrls(Employee $employee, bool $canReadHistories, bool $canReadDiscipline, bool $canReadDocuments): void
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
            $employee->documents->each(function (Document $document) use ($employee): void {
                $fileAvailable = $this->attachments->availableDocumentPath($employee, $document, DocumentCategory::keys()) !== null;
                $document->setAttribute('rbac_download_url', $fileAvailable ? route('rbac.pegawai.documents.download', ['employee' => $employee, 'document' => $document]) : null);
                $document->setAttribute('rbac_file_size_label', $fileAvailable ? $document->fileSizeLabel() : 'File tidak ditemukan');
            });
        }
    }
}
