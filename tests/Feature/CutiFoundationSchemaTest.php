<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeaveApprovalChainStep;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefJenisCuti;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Memastikan fondasi penyimpanan revisi cuti tersedia sebelum alur approval dinamis dipakai.
 */
class CutiFoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_fondasi_revisi_cuti_tersedia(): void
    {
        $expectedColumns = [
            'leave_approval_chains' => [
                'id',
                'employee_id',
                'name',
                'is_active',
                'effective_from',
                'effective_until',
                'created_by',
                'updated_by',
                'change_reason',
            ],
            'leave_approval_chain_steps' => [
                'id',
                'leave_approval_chain_id',
                'step_order',
                'step_type',
                'role_label',
                'approver_role_key',
                'approver_employee_id',
                'is_final',
            ],
            'leave_pybmc_global_config' => [
                'id',
                'approver_employee_id',
                'effective_from',
                'created_by',
                'change_reason',
            ],
            'leave_request_steps' => [
                'id',
                'leave_request_id',
                'step_order',
                'step_type',
                'role_label',
                'approver_employee_id',
                'status',
                'is_final',
                'skipped_reason',
                'acted_at',
                'decision_note',
            ],
            'leave_balances' => [
                'sisa_n2',
                'sisa_n1',
                'sisa_tahun_berjalan',
                'terpakai_tahun_berjalan',
                'hangus',
            ],
            'leave_balance_ledger' => [
                'id',
                'employee_id',
                'leave_request_id',
                'leave_balance_id',
                'tahun',
                'event_type',
                'amount',
                'source_year',
                'sumber_carry_over',
                'reason',
                'dedup_key',
                'metadata',
                'created_by',
                'occurred_at',
            ],
            'leave_proofs' => [
                'id',
                'leave_request_id',
                'token',
                'document_path',
                'document_mime',
                'generated_by',
                'generated_at',
                'metadata',
            ],
        ];

        foreach ($expectedColumns as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "Tabel {$table} wajib tersedia.");
            $this->assertTrue(Schema::hasColumns($table, $columns), "Kolom fondasi {$table} belum lengkap.");
        }
    }

    public function test_model_fondasi_revisi_cuti_memiliki_relasi_dasar(): void
    {
        $pemohon = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan Uji',
            'code' => 'tahunan_uji',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $cuti = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-06',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji fondasi revisi cuti.',
            'status' => 'Draft',
        ]);
        $saldo = LeaveBalance::create([
            'employee_id' => $pemohon->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
        ]);
        $chain = LeaveApprovalChain::create([
            'employee_id' => $pemohon->id,
            'name' => 'Rantai utama',
            'effective_from' => '2026-01-01',
            'change_reason' => 'Uji fondasi.',
        ]);

        LeaveApprovalChainStep::create([
            'leave_approval_chain_id' => $chain->id,
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Atasan Langsung',
            'approver_employee_id' => $approver->id,
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $cuti->id,
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Atasan Langsung',
            'approver_employee_id' => $approver->id,
            'status' => 'pending',
        ]);
        LeaveBalanceLedger::create([
            'employee_id' => $pemohon->id,
            'leave_balance_id' => $saldo->id,
            'tahun' => 2026,
            'event_type' => 'opening_balance_set',
            'amount' => 12,
        ]);
        $proof = LeaveProof::create([
            'leave_request_id' => $cuti->id,
            'token' => 'token-uji-fondasi',
            'document_mime' => 'application/pdf',
            'metadata' => [
                'snapshot' => [
                    'nomor_pengajuan' => 'CUTI-2026-0001',
                ],
            ],
        ]);

        $this->assertCount(1, $chain->steps);
        $this->assertCount(1, $cuti->steps);
        $this->assertTrue($saldo->ledgerEntries()->where('event_type', 'opening_balance_set')->exists());
        $this->assertSame('token-uji-fondasi', $cuti->proof->token);
        $this->assertSame('application/pdf', $proof->document_mime);
        $this->assertSame([
            'snapshot' => [
                'nomor_pengajuan' => 'CUTI-2026-0001',
            ],
        ], $proof->fresh()->metadata);
    }

    public function test_constraint_fondasi_revisi_cuti_menolak_duplikasi_kritis(): void
    {
        $pemohon = Employee::factory()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan Constraint',
            'code' => 'tahunan_constraint',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $cuti = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-06',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji constraint fondasi revisi cuti.',
            'status' => 'Draft',
        ]);
        $chain = LeaveApprovalChain::create([
            'employee_id' => $pemohon->id,
            'name' => 'Rantai aktif',
            'effective_from' => '2026-01-01',
        ]);

        $this->expectException(QueryException::class);
        LeaveApprovalChain::create([
            'employee_id' => $pemohon->id,
            'name' => 'Rantai aktif duplikat',
            'effective_from' => '2026-01-02',
        ]);
    }

    public function test_constraint_fondasi_revisi_cuti_menolak_duplikasi_token_bukti(): void
    {
        $pemohon = Employee::factory()->create();
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Tahunan Token',
            'code' => 'tahunan_token',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $cuti = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-06',
            'tanggal_selesai' => '2026-07-06',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji token proof.',
            'status' => 'Draft',
        ]);
        $cutiLain = LeaveRequest::create([
            'employee_id' => $pemohon->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-07-07',
            'tanggal_selesai' => '2026-07-07',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Uji token proof lain.',
            'status' => 'Draft',
        ]);

        LeaveProof::create(['leave_request_id' => $cuti->id, 'token' => 'token-duplikat']);
        $this->assertThrows(
            fn () => LeaveProof::create(['leave_request_id' => $cutiLain->id, 'token' => 'token-duplikat']),
            QueryException::class,
        );
    }

    public function test_constraint_fondasi_revisi_cuti_menolak_duplikasi_dedup_key_ledger(): void
    {
        $pemohon = Employee::factory()->create();
        $saldo = LeaveBalance::create([
            'employee_id' => $pemohon->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
        ]);

        LeaveBalanceLedger::create([
            'employee_id' => $pemohon->id,
            'leave_balance_id' => $saldo->id,
            'tahun' => 2026,
            'event_type' => 'opening_balance_set',
            'amount' => 12,
            'dedup_key' => 'saldo-awal-2026',
        ]);
        $this->assertThrows(
            fn () => LeaveBalanceLedger::create([
                'employee_id' => $pemohon->id,
                'leave_balance_id' => $saldo->id,
                'tahun' => 2026,
                'event_type' => 'opening_balance_set',
                'amount' => 12,
                'dedup_key' => 'saldo-awal-2026',
            ]),
            QueryException::class,
        );
    }
}
