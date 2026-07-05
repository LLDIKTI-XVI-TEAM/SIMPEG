<?php

namespace App\Support\Employees;

use App\Models\Employee;
use Illuminate\Support\Arr;

/**
 * Helper untuk payload detail pegawai.
 *
 * Menyediakan metode untuk membatasi field sensitif yang dikembalikan ke frontend,
 * serta eager-load semua relasi yang diperlukan detail pegawai sesuai ERD.
 */
class EmployeeDetailPayload
{
    /**
     * Eager-load semua relasi untuk detail pegawai dengan order dan nested select sesuai ERD.
     *
     * Relasi diurutkan berdasarkan prioritas tampilan di UI:
     * - Agama, Status Kawin, Jenis Pegawai (ref kecil)
     * - Keluarga (latest)
     * - Pengangkatan (TMT desc)
     * - Riwayat Pangkat, Jabatan, Gaji (TMT desc dengan nested select)
     * - Disiplin, Pendidikan, Dokumen (tanggal desc / latest)
     * - Atasan, Saldo Cuti, Pengajuan Cuti, Alert EWS (tanggal desc / order)
     */
    public function loadRelations(Employee $employee): Employee
    {
        return $employee->load([
            'agama:id,nama',
            'statusKawin:id,nama',
            'jenisPegawai:id,nama',
            'statusPegawai:id,nama',
            'families' => fn ($query) => $query->latest(),
            'appointments' => fn ($query) => $query->orderByDesc('tmt_pengangkatan'),
            'rankHistories' => fn ($query) => $query->with('golongan:id,kode,nama')->orderByDesc('tmt_pangkat'),
            'positionHistories' => fn ($query) => $query
                ->with([
                    'jabatan:id,nama,jenis_jabatan_id',
                    'jenisJabatan:id,nama,maks_usia_pensiun',
                    'eselon:id,kode,nama',
                    'unitKerja:id,nama',
                ])
                ->orderByDesc('tmt_jabatan'),
            'salaryHistories' => fn ($query) => $query->orderByDesc('tmt_kgb'),
            'disciplineRecords' => fn ($query) => $query->orderByDesc('tanggal_mulai'),
            'educationHistories' => fn ($query) => $query->with('jenjang:id,nama')->orderByDesc('tahun_lulus'),
            'documents' => fn ($query) => $query->latest(),
            'kepalaBagian:id,nama_lengkap,nip,jabatan_terakhir',
            'supervisorAssignments' => fn ($query) => $query
                ->with('supervisor:id,nama_lengkap,nip,jabatan_terakhir')
                ->orderByRaw('tanggal_berakhir is null desc')
                ->orderByDesc('tanggal_mulai'),
            'leaveBalances' => fn ($query) => $query->orderByDesc('tahun'),
            'leaveRequests' => fn ($query) => $query->with('jenisCuti:id,nama')->latest(),
            'ewsAlerts' => fn ($query) => $query->orderBy('target_date'),
        ]);
    }

    /**
     * Membatasi field sensitif pegawai agar tidak terbuka ke frontend.
     *
     * Field yang dikecualikan (sensitif): nik, no_kk, keycloak_id, role, dan lainnya.
     * Field yang diizinkan: identitas dasar, pangkat, jabatan, riwayat, saldo cuti, alert.
     * Relasi yang disertakan: agama, status kawin, jenis pegawai, keluarga, pengangkatan, dll.
     */
    public function response(Employee $employee): array
    {
        return [
            ...Arr::only($employee->toArray(), [
                'id',
                'nama_lengkap',
                'nip',
                'tempat_lahir',
                'tanggal_lahir',
                'jenis_kelamin',
                'golongan_darah',
                'foto',
                'jenis_pegawai_id',
                'status_aktif',
                'status_pegawai_id',
                'status_keterangan',
                'golongan_terakhir',
                'pangkat_terakhir',
                'jabatan_terakhir',
                'kelas_jabatan',
                'kelas_jabatan_terakhir',
                'pendidikan_terakhir',
                'prodi_pendidikan_terakhir',
                'tanggal_pensiun',
                'tanggal_kenaikan_pangkat_berikutnya',
                'tanggal_kgb_berikutnya',
                'profil_status',
                'email',
                'email_pribadi',
                'kepala_bagian_id',
                'is_kinerja_baik',
                'created_at',
                'updated_at',
            ]),
            'agama' => $employee->agama,
            'status_kawin' => $employee->statusKawin,
            'jenis_pegawai' => $employee->jenisPegawai,
            'status_pegawai' => $employee->statusPegawai,
            'families' => $employee->families,
            'appointments' => $employee->appointments,
            'rank_histories' => $employee->rankHistories,
            'position_histories' => $employee->positionHistories,
            'salary_histories' => $employee->salaryHistories,
            'discipline_records' => $employee->disciplineRecords,
            'education_histories' => $employee->educationHistories,
            'documents' => $employee->documents,
            'kepala_bagian' => $employee->kepalaBagian,
            'supervisor_assignments' => $employee->supervisorAssignments,
            'leave_balances' => $employee->leaveBalances,
            'leave_requests' => $employee->leaveRequests,
            'ews_alerts' => $employee->ewsAlerts,
        ];
    }
}
