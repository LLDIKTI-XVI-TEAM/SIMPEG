<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefStatusPegawai;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeActiveStatusPredicateTest extends TestCase
{
    use RefreshDatabase;

    public function test_predicate_instance_dan_scope_menormalisasi_kelompok_aktif_secara_konsisten(): void
    {
        $activeVariants = ['Aktif', ' aktif ', 'AKTIF', 'AKTIF/KHUSUS'];
        $activeEmployeeIds = [];

        foreach ($activeVariants as $index => $variant) {
            $employee = $this->employeeWithRawStatusGroup("AKTIF_VARIAN_{$index}", $variant);

            $this->assertTrue($employee->isActive(), "Kelompok {$variant} harus dianggap aktif.");
            $activeEmployeeIds[] = $employee->id;
        }

        foreach (['', 'tidak-dikenal'] as $index => $variant) {
            $employee = $this->employeeWithRawStatusGroup("INVALID_VARIAN_{$index}", $variant);

            $this->assertFalse($employee->isActive(), "Kelompok {$variant} harus ditolak fail-closed.");
        }

        $missingRelationEmployee = Employee::factory()->create();
        $missingRelationEmployee->status_pegawai_id = null;
        $missingRelationEmployee->save();

        $this->assertFalse($missingRelationEmployee->refresh()->isActive());

        $scopedEmployeeIds = Employee::query()
            ->whereActiveStatus()
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        sort($activeEmployeeIds);

        $this->assertSame($activeEmployeeIds, $scopedEmployeeIds);
    }

    private function employeeWithRawStatusGroup(string $suffix, string $group): Employee
    {
        $status = RefStatusPegawai::query()->create([
            'kode' => "STATUS_{$suffix}",
            'nama' => "Status {$suffix}",
            'kelompok' => 'Nonaktif',
            'keterangan' => null,
            'is_default' => false,
            'is_active' => true,
        ]);

        // Data lama dapat memiliki variasi kapital/spasi; bypass mutator agar
        // predicate membaca bentuk yang benar-benar tersimpan di PostgreSQL.
        DB::table('ref_status_pegawai')->where('id', $status->id)->update(['kelompok' => $group]);

        $employee = Employee::factory()->create();
        $employee->status_pegawai_id = $status->id;
        $employee->save();

        return $employee->refresh()->load('statusPegawai');
    }
}
