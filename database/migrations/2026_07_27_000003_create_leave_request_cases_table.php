<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private const CASE_TYPE_CODES = ['melahirkan', 'cltn'];

    public function up(): void
    {
        Schema::create('leave_request_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('jenis_cuti_id')->constrained('ref_jenis_cuti')->restrictOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'jenis_cuti_id'], 'leave_request_cases_employee_type_index');
        });

        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->foreignUuid('leave_request_case_id')
                ->nullable()
                ->after('jenis_cuti_id')
                ->constrained('leave_request_cases')
                ->restrictOnDelete();
        });

        // Baris historis tidak dapat dikaitkan dari teks alasan. Setiap pengajuan
        // lama menerima rangkaian eksplisitnya sendiri agar riwayat tetap utuh dan
        // tidak ada hubungan yang ditebak secara retrospektif.
        DB::table('leave_requests')
            ->join('ref_jenis_cuti', 'ref_jenis_cuti.id', '=', 'leave_requests.jenis_cuti_id')
            ->whereIn('ref_jenis_cuti.code', self::CASE_TYPE_CODES)
            ->whereNull('leave_requests.leave_request_case_id')
            ->orderBy('leave_requests.id')
            ->select([
                'leave_requests.id',
                'leave_requests.employee_id',
                'leave_requests.jenis_cuti_id',
                'leave_requests.created_at',
                'leave_requests.updated_at',
            ])
            ->each(function (object $leaveRequest): void {
                $caseId = (string) Str::uuid();

                DB::table('leave_request_cases')->insert([
                    'id' => $caseId,
                    'employee_id' => $leaveRequest->employee_id,
                    'jenis_cuti_id' => $leaveRequest->jenis_cuti_id,
                    'created_by' => null,
                    'created_at' => $leaveRequest->created_at ?? now(),
                    'updated_at' => $leaveRequest->updated_at ?? now(),
                ]);

                DB::table('leave_requests')
                    ->where('id', $leaveRequest->id)
                    ->update(['leave_request_case_id' => $caseId]);
            });
    }

    public function down(): void
    {
        if (DB::table('leave_request_cases')->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena rangkaian pengajuan cuti sudah berisi data. Arsipkan data sebelum menurunkan migration.');
        }

        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('leave_request_case_id');
        });

        Schema::dropIfExists('leave_request_cases');
    }
};
