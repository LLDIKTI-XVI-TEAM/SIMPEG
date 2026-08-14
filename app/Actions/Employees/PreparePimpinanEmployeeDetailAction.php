<?php

namespace App\Actions\Employees;

use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\SupervisorAssignment;
use App\Services\Employees\EmployeeHistoryAttachmentService;
use App\Support\Documents\DocumentCategory;
use App\Support\Employees\EmployeeProfilePresentation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PreparePimpinanEmployeeDetailAction
{
    public function __construct(private readonly EmployeeHistoryAttachmentService $attachments) {}

    /**
     * Menyiapkan seluruh data detail pegawai yang boleh dibaca Pimpinan tanpa membawa opsi mutasi Admin.
     *
     * @return array{p: Employee, statusPresentation: array{label: string, badge: string, dot: string, effectiveDate: Carbon|null}, activePosition: PositionHistory|null, latestRank: RankHistory|null, latestStatusHistory: EmployeeStatusHistory|null, activeSupervisorAssignments: Collection<int, SupervisorAssignment>, retirementDate: Carbon|null}
     */
    public function execute(string $employeeId): array
    {
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
                'statusHistories' => fn ($query) => $query
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
                'appointment',
                'rankHistories' => fn ($query) => $query
                    ->with('golongan:id,kode,nama')
                    ->orderByDesc('tmt_pangkat'),
                'positionHistories' => fn ($query) => $query
                    ->with(['jabatan:id,nama', 'unitKerja:id,nama'])
                    ->orderByDesc('is_latest')
                    ->orderByDesc('tmt_jabatan'),
                'salaryHistories' => fn ($query) => $query->orderByDesc('tmt_kgb'),
                'disciplineRecords' => fn ($query) => $query->orderByDesc('tanggal_mulai'),
                'educationHistories' => fn ($query) => $query
                    ->with('jenjang:id,nama,urutan')
                    ->orderByDesc('tahun_lulus'),
                'documents' => fn ($query) => $query
                    // KTP/KK tidak masuk payload karena metadata dan file-nya memuat identitas sensitif.
                    ->whereIn('jenis_dokumen', DocumentCategory::visibleToPimpinanKeys())
                    ->orderByDesc('tanggal_dokumen'),
                'families' => fn ($query) => $query
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

        $this->prepareAttachmentDownloadUrls($employee);
        $latestStatusHistory = $employee->statusHistories->firstWhere('is_latest', true)
            ?? $employee->statusHistories->first();
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
