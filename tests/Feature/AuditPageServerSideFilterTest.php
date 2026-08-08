<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Halaman audit harus menyaring dan memotong data di basis data, bukan mengirim seluruh
 * tabel ke browser. Volume audit tumbuh terus sehingga penyaringan di sisi klien akan
 * memperlambat halaman seiring pemakaian.
 */
class AuditPageServerSideFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_halaman_audit_memotong_data_pada_dua_puluh_lima_baris_per_halaman(): void
    {
        foreach (range(1, 30) as $urutan) {
            $this->auditLog(['user_name' => 'Operator '.$urutan]);
        }

        $response = $this->actingAs($this->admin())->get('/dashboard/audit');

        $response->assertOk();
        $paginator = $response->viewData('auditLogs');
        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertSame(25, $paginator->perPage());
        $this->assertCount(25, $paginator->items());
        $this->assertSame(30, $paginator->total());
    }

    public function test_halaman_audit_mencari_berdasarkan_nama_operator(): void
    {
        $this->auditLog(['user_name' => 'Sinta Pengelola']);
        $this->auditLog(['user_name' => 'Bagas Pengelola']);

        $response = $this->actingAs($this->admin())->get('/dashboard/audit?'.http_build_query(['q' => 'sinta']));

        $response->assertOk();
        $paginator = $response->viewData('auditLogs');
        $this->assertSame(1, $paginator->total());
        // Pilihan pada penyaring tetap memuat seluruh operator, karena itu pemeriksaan dilakukan
        // pada baris hasil, bukan pada keseluruhan halaman.
        $this->assertSame(['Sinta Pengelola'], array_column($paginator->items(), 'operator'));
    }

    public function test_halaman_audit_mencari_berdasarkan_id_record(): void
    {
        $dicari = (string) Str::uuid();
        $this->auditLog(['auditable_id' => $dicari]);
        $this->auditLog(['auditable_id' => (string) Str::uuid()]);

        $response = $this->actingAs($this->admin())->get('/dashboard/audit?'.http_build_query(['q' => $dicari]));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('auditLogs')->total());
    }

    public function test_halaman_audit_menyaring_berdasarkan_jenis_event_modul_dan_periode(): void
    {
        $this->auditLog(['event' => 'CREATE', 'auditable_type' => 'Employee', 'created_at' => '2026-03-01 08:00:00']);
        $this->auditLog(['event' => 'UPDATE', 'auditable_type' => 'Employee', 'created_at' => '2026-03-02 08:00:00']);
        $this->auditLog(['event' => 'CREATE', 'auditable_type' => 'LeaveRequest', 'created_at' => '2026-03-03 08:00:00']);
        $this->auditLog(['event' => 'CREATE', 'auditable_type' => 'Employee', 'created_at' => '2026-05-01 08:00:00']);

        $response = $this->actingAs($this->admin())->get('/dashboard/audit?'.http_build_query([
            'event' => 'CREATE',
            'modul' => 'Employee',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ]));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('auditLogs')->total());
    }

    public function test_halaman_audit_mengurutkan_terbaru_lebih_dahulu(): void
    {
        $this->auditLog(['user_name' => 'Paling Lama', 'created_at' => '2026-01-01 08:00:00']);
        $this->auditLog(['user_name' => 'Paling Baru', 'created_at' => '2026-06-01 08:00:00']);

        $response = $this->actingAs($this->admin())->get('/dashboard/audit');

        $response->assertOk();
        $baris = $response->viewData('auditLogs')->items();
        $this->assertSame('Paling Baru', $baris[0]['operator']);
        $this->assertSame('Paling Lama', $baris[1]['operator']);
    }

    public function test_halaman_audit_tidak_mengirim_seluruh_tabel_ke_browser(): void
    {
        $penandaHalamanBerikutnya = null;

        foreach (range(1, 30) as $urutan) {
            $penanda = (string) Str::uuid();
            // Baris paling lama berada di halaman terakhir karena urutan bawaan terbaru dahulu.
            Carbon::setTestNow(now()->subDays(31 - $urutan));

            try {
                $this->auditLog(['auditable_id' => $penanda]);
            } finally {
                Carbon::setTestNow();
            }

            if ($urutan === 1) {
                $penandaHalamanBerikutnya = $penanda;
            }
        }

        $response = $this->actingAs($this->admin())->get('/dashboard/audit');

        $response->assertOk();
        $isi = $response->getContent();
        $this->assertIsString($isi);
        $this->assertIsString($penandaHalamanBerikutnya);
        // Pengenal record baris ke-26 dan setelahnya tidak boleh ikut dikirim bersama halaman
        // pertama, baik pada tabel maupun pada state yang dipakai panel detail.
        $this->assertStringNotContainsString($penandaHalamanBerikutnya, $isi);
    }

    public function test_pilihan_filter_diambil_dari_basis_data_bukan_dari_seluruh_baris_di_browser(): void
    {
        $this->auditLog(['user_name' => 'Operator Satu', 'auditable_type' => 'Employee']);
        $this->auditLog(['user_name' => 'Operator Dua', 'auditable_type' => 'LeaveRequest']);

        $response = $this->actingAs($this->admin())->get('/dashboard/audit');

        $response->assertOk();
        $this->assertSame(['Operator Dua', 'Operator Satu'], $response->viewData('operatorOptions'));
        $this->assertSame(['Employee', 'LeaveRequest'], $response->viewData('modulOptions'));
    }

    public function test_halaman_audit_mengabaikan_periode_yang_bukan_tanggal(): void
    {
        $this->auditLog();

        // Nilai yang bukan tanggal tidak boleh sampai ke klausa perbandingan waktu, karena
        // PostgreSQL akan menolaknya dan permintaan berakhir sebagai galat peladen.
        $response = $this->actingAs($this->admin())->get('/dashboard/audit?'.http_build_query([
            'from' => 'bukan-tanggal',
            'to' => '2026-13-99',
        ]));

        $response->assertOk();
        $this->assertSame(1, $response->viewData('auditLogs')->total());
    }

    public function test_halaman_audit_memakai_jumlah_baris_bawaan_ketika_permintaan_tidak_masuk_akal(): void
    {
        $this->auditLog();

        $response = $this->actingAs($this->admin())->get('/dashboard/audit?'.http_build_query(['per_page' => '0']));

        $response->assertOk();
        $this->assertSame(25, $response->viewData('auditLogs')->perPage());

        $response = $this->actingAs($this->admin())->get('/dashboard/audit?'.http_build_query(['per_page' => '999']));

        $response->assertOk();
        $this->assertSame(100, $response->viewData('auditLogs')->perPage());
    }

    public function test_halaman_audit_mengabaikan_kolom_pengurutan_di_luar_daftar(): void
    {
        $this->auditLog(['user_name' => 'Paling Lama', 'created_at' => '2026-01-01 08:00:00']);
        $this->auditLog(['user_name' => 'Paling Baru', 'created_at' => '2026-06-01 08:00:00']);

        $response = $this->actingAs($this->admin())->get('/dashboard/audit?'.http_build_query([
            'sort' => 'user_agent) --',
            'direction' => 'naik',
        ]));

        $response->assertOk();
        $baris = $response->viewData('auditLogs')->items();
        $this->assertSame('Paling Baru', $baris[0]['operator']);
    }

    public function test_halaman_audit_mencari_berdasarkan_pengenal_record_konfigurasi(): void
    {
        // Audit konfigurasi tidak memiliki auditable_id, pengenal recordnya tersimpan sebagai
        // kunci pada payload, dan itulah nilai yang ditampilkan pada kolom ID Record.
        $this->auditLog([
            'event' => 'CONFIG_UPDATE',
            'auditable_type' => 'EwsConfig',
            'auditable_id' => null,
            'old_values' => ['key' => 'ews_scheduler_time', 'value' => '07:00'],
            'new_values' => ['key' => 'ews_scheduler_time', 'value' => '08:30'],
        ]);
        $this->auditLog();

        $response = $this->actingAs($this->admin())->get('/dashboard/audit?'.http_build_query([
            'q' => 'ews_scheduler_time',
        ]));

        $response->assertOk();
        $paginator = $response->viewData('auditLogs');
        $this->assertSame(1, $paginator->total());
        $this->assertSame('ews_scheduler_time', $paginator->items()[0]['record_id']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function auditLog(array $overrides = []): AuditLog
    {
        $createdAt = $overrides['created_at'] ?? now();
        unset($overrides['created_at']);

        // Audit log menolak pembaruan, sehingga waktu pembuatan ditetapkan sebelum baris dibuat.
        Carbon::setTestNow($createdAt);

        try {
            return AuditLog::query()->create(array_merge([
                'user_id' => null,
                'user_name' => 'Petugas Uji',
                'event' => 'UPDATE',
                'auditable_type' => 'Employee',
                'auditable_id' => (string) Str::uuid(),
                'old_values' => ['nama_lengkap' => 'Sebelum'],
                'new_values' => ['nama_lengkap' => 'Sesudah'],
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
            ], $overrides));
        } finally {
            Carbon::setTestNow();
        }
    }
}
