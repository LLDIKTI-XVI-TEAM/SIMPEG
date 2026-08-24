<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\PrepareEmployeeDocumentRowsAction;
use App\Models\Appointment;
use App\Models\Document;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrepareEmployeeDocumentRowsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_sk_is_presented_in_the_sk_table_with_a_safe_generic_label(): void
    {
        $employee = Employee::factory()->create();
        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'SK',
            'nama_dokumen' => 'SK Legacy',
            'file_path' => 'pegawai/sk-legacy.pdf',
        ]);

        $rows = app(PrepareEmployeeDocumentRowsAction::class)->execute($this->loadDocumentRelations($employee));

        $this->assertSame($document->id, $rows['sk'][0]['id']);
        $this->assertSame('Dokumen SK', $rows['sk'][0]['kategori_label']);
        $this->assertSame([], $rows['others']);
    }

    public function test_secondary_appointment_file_cannot_be_mutated_from_berkas_lainnya(): void
    {
        $employee = Employee::factory()->create();
        $protectedPath = 'pegawai/sk-pengangkatan-kedua.pdf';

        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'CPNS',
            'tmt_pengangkatan' => '2020-01-01',
            'no_sk' => 'SK-CPNS-001',
            'tanggal_sk' => '2019-12-20',
            'file_sk' => 'pegawai/sk-pengangkatan-pertama.pdf',
        ]);
        Appointment::create([
            'employee_id' => $employee->id,
            'jenis_pengangkatan' => 'PNS',
            'tmt_pengangkatan' => '2021-01-01',
            'no_sk' => 'SK-PNS-001',
            'tanggal_sk' => '2020-12-20',
            'file_sk' => $protectedPath,
        ]);
        $document = Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => 'lainnya',
            'nama_dokumen' => 'Lampiran Pengangkatan Legacy',
            'file_path' => $protectedPath,
        ]);

        $rows = app(PrepareEmployeeDocumentRowsAction::class)->execute($this->loadDocumentRelations($employee));

        $this->assertSame($document->id, $rows['others'][0]['id']);
        $this->assertFalse($rows['others'][0]['can_mutate']);
    }

    /** Memuat seluruh sumber lampiran agar keputusan mutasi mencerminkan profil pegawai. */
    private function loadDocumentRelations(Employee $employee): Employee
    {
        return $employee->load([
            'rankHistories',
            'positionHistories',
            'salaryHistories',
            'statusHistories',
            'disciplineRecords',
            'educationHistories',
            'appointments',
            'documents',
        ]);
    }
}
