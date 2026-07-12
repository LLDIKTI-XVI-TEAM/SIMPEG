<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PimpinanRankHistoryReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_pimpinan_can_preview_and_download_fixed_rank_history_reports(): void
    {
        $this->seed(RbacSeeder::class);

        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Dimas Prakoso',
            'nip' => '198404042010041004',
        ]);
        $rank = RefGolongan::create(['kode' => 'III/d', 'nama' => 'Penata Tingkat I']);
        RankHistory::create([
            'employee_id' => $employee->id,
            'golongan_id' => $rank->id,
            'tmt_pangkat' => '2026-04-01',
            'no_sk' => 'SK-123/2026',
            'tanggal_sk' => '2026-03-20',
            'is_latest' => true,
        ]);
        $user = User::factory()->pimpinan()->create();

        $this->actingAs($user)
            ->get(route('pimpinan.laporan.kepangkatan', ['employee_id' => $employee->id]))
            ->assertOk()
            ->assertSee('Dimas Prakoso')
            ->assertSee('SK-123/2026')
            ->assertDontSee('onclick="alert(', false)
            ->assertDontSee('action="#"', false);

        $this->actingAs($user)
            ->get(route('pimpinan.laporan.kepangkatan.excel', ['employee_id' => $employee->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $pdf = $this->actingAs($user)
            ->get(route('pimpinan.laporan.kepangkatan.pdf', ['employee_id' => $employee->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF-', $pdf->streamedContent());
    }
}
