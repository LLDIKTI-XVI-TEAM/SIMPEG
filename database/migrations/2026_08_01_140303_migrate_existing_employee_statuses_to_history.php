<?php

use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Populate employee_status_histories dari data status yang ada di tabel employees.
     * Hanya employees dengan status_pegawai_id yang dimigrasi.
     */
    public function up(): void
    {
        $employees = DB::table('employees')
            ->whereNotNull('status_pegawai_id')
            ->select([
                'id as employee_id',
                'status_pegawai_id',
                'status_aktif as status_nama',
                'status_alasan as alasan',
                'status_deskripsi as deskripsi',
                'status_tanggal as tanggal_efektif',
                'status_nomor_berkas as nomor_berkas',
                'status_berkas_path as file_sk',
                'created_at',
                'updated_at',
            ])
            ->get();

        foreach ($employees as $employee) {
            DB::table('employee_status_histories')->insert([
                'id' => DB::raw('gen_random_uuid()'),
                'employee_id' => $employee->employee_id,
                'status_pegawai_id' => $employee->status_pegawai_id,
                'status_nama' => $employee->status_nama ?? 'Aktif',
                'alasan' => $employee->alasan ?? 'Migrasi data existing',
                'deskripsi' => $employee->deskripsi,
                'tanggal_efektif' => $employee->tanggal_efektif ?? now(),
                'nomor_berkas' => $employee->nomor_berkas,
                'file_sk' => $employee->file_sk,
                'changed_by_user_id' => null,
                'is_latest' => true,
                'created_at' => $employee->created_at ?? now(),
                'updated_at' => $employee->updated_at ?? now(),
            ]);
        }
    }

    /**
     * Rollback menghapus semua records yang dimigrasi.
     */
    public function down(): void
    {
        DB::table('employee_status_histories')->truncate();
    }
};
