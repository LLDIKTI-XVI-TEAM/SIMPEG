<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\SupervisorAssignment;
use App\Support\Documents\DocumentCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class PreparePimpinanEmployeeDetailAction
{
    /**
     * Menyiapkan seluruh data detail pegawai yang boleh dibaca Pimpinan tanpa membawa opsi mutasi Admin.
     *
     * @return array{p: Employee, statusPresentation: array{label: string, badge: string, dot: string, effectiveDate: Carbon|null}, activePosition: PositionHistory|null, activeSupervisorAssignments: Collection<int, SupervisorAssignment>}
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
                'email_pribadi',
                'no_hp',
                'no_telepon_rumah',
                'alamat',
                'tanggal_pensiun',
            ])
            ->with([
                'agama:id,nama',
                'statusKawin:id,nama',
                'jenisPegawai:id,nama',
                'statusPegawai:id,kode,nama,kelompok',
                'statusHistories' => fn ($query) => $query
                    ->select(['id', 'employee_id', 'tanggal_efektif', 'is_latest'])
                    ->orderByDesc('is_latest')
                    ->orderByDesc('tanggal_efektif'),
                'appointment',
                'rankHistories' => fn ($query) => $query
                    ->with('golongan:id,nama')
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
                        'supervisor:id,nama_lengkap,jabatan_terakhir',
                        'supervisor.positionHistories' => fn ($positions) => $positions
                            ->select(['id', 'employee_id', 'unit_kerja_id', 'nama_jabatan', 'is_latest'])
                            ->where('is_latest', true)
                            ->with('unitKerja:id,nama'),
                    ]),
            ])
            ->findOrFail($employeeId);

        // Status reference adalah sumber utama; snapshot dipertahankan sebagai fallback kompatibilitas.
        $statusLabel = $employee->statusPegawai?->nama ?? $employee->status_aktif ?? '-';
        $statusCode = mb_strtolower(trim((string) ($employee->statusPegawai?->kode ?? '')));
        $statusGroup = mb_strtolower(trim((string) ($employee->statusPegawai?->kelompok ?? '')));
        $normalizedLabel = mb_strtolower(trim($statusLabel));
        $isActive = $statusCode === 'aktif'
            || in_array($statusGroup, ['aktif', 'aktif/khusus'], true)
            || ($statusCode === '' && $normalizedLabel === 'aktif');
        $isPensiun = $statusCode === 'pensiun'
            || ($statusCode === '' && $normalizedLabel === 'pensiun');
        $statusStyle = match (true) {
            $isActive => ['badge' => 'bg-success/10 text-success', 'dot' => 'bg-success'],
            $isPensiun => ['badge' => 'bg-danger/10 text-danger', 'dot' => 'bg-danger'],
            default => ['badge' => 'bg-muted/10 text-muted', 'dot' => 'bg-muted'],
        };
        $latestStatusHistory = $employee->statusHistories->firstWhere('is_latest', true)
            ?? $employee->statusHistories->first();
        $activePosition = $employee->positionHistories->firstWhere('is_latest', true);

        return [
            'p' => $employee,
            'statusPresentation' => [
                'label' => $statusLabel,
                'effectiveDate' => $employee->status_tanggal ?? $latestStatusHistory?->tanggal_efektif,
                ...$statusStyle,
            ],
            'activePosition' => $activePosition,
            'activeSupervisorAssignments' => $employee->supervisorAssignments,
        ];
    }
}
