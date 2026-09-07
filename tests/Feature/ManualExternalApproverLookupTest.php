<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualExternalApproverLookupTest extends TestCase
{
    use RefreshDatabase;

    private const LOOKUP_PATH = '/cuti/pemakaian-manual/penyetuju/cari';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_lookup_mengikuti_grant_dan_revoke_tanpa_membatasi_kandidat_ke_scope_pemilik(): void
    {
        $manualPermission = Permission::query()->where('name', 'cuti.manual.manage')->sole();

        $approver = Employee::factory()->create(['nama_lengkap' => 'Referensi Penyetuju']);
        foreach (['super_admin', 'pimpinan', 'kepala_bagian', 'pegawai'] as $role) {
            Role::query()->where('name', $role)->sole()->permissions()->syncWithoutDetaching([$manualPermission->id]);

            $this->actingAs(User::factory()->create(['role' => $role]))
                ->getJson(self::LOOKUP_PATH.'?q=Referensi')
                ->assertOk()->assertJsonPath('data.0.id', $approver->id);

            Role::query()->where('name', $role)->sole()->permissions()->detach($manualPermission->id);
            $this->getJson(self::LOOKUP_PATH.'?q=Referensi')
                ->assertForbidden();
        }

        $adminRole = Role::query()->where('name', 'admin_kepegawaian')->sole();
        $adminRole->permissions()->detach($manualPermission->id);

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->getJson(self::LOOKUP_PATH.'?q=Pegawai')
            ->assertForbidden();
    }

    public function test_lookup_menormalkan_unicode_whitespace_mengamankan_wildcard_dan_hanya_mengirim_allowlist(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $target = Employee::factory()->create([
            'nama_lengkap' => "Ni Luh Śakti O'Connor",
            'nip' => '198765432100000001',
            'jabatan_terakhir' => 'Pejabat Penguji',
            'status_aktif' => 'Aktif',
            'email' => 'rahasia@example.test',
            'alamat' => 'Alamat privat tidak boleh terkirim.',
        ]);
        Employee::factory()->create([
            'nama_lengkap' => 'Ni Luh Sakti Lain',
            'nip' => '198765432100000002',
            'status_aktif' => 'Aktif',
        ]);

        $response = $this->actingAs($actor)
            ->getJson(self::LOOKUP_PATH.'?q='.urlencode("  Ni\u{00A0}Luh\u{2003}Śakti  "));

        $response->assertOk()
            ->assertHeader('Cache-Control')
            ->assertExactJson([
                'data' => [[
                    'id' => $target->id,
                    'nama_lengkap' => "Ni Luh Śakti O'Connor",
                    'nip' => '198765432100000001',
                    'jabatan_terakhir' => 'Pejabat Penguji',
                ]],
            ]);
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);

        foreach (['%', '_', '\\'] as $wildcard) {
            $this->actingAs($actor)
                ->getJson(self::LOOKUP_PATH.'?q='.urlencode($wildcard))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('q');
        }

        foreach (["O'Connor", '198765432100000001'] as $query) {
            $this->actingAs($actor)
                ->getJson(self::LOOKUP_PATH.'?q='.urlencode($query))
                ->assertOk()
                ->assertJsonPath('data.0.id', $target->id)
                ->assertJsonMissing(['email' => 'rahasia@example.test'])
                ->assertJsonMissing(['alamat' => 'Alamat privat tidak boleh terkirim.']);
        }

        $percent = Employee::factory()->create(['nama_lengkap' => 'Kode%AA', 'status_aktif' => 'Aktif']);
        $underscore = Employee::factory()->create(['nama_lengkap' => 'Kode_AA', 'status_aktif' => 'Aktif']);
        $backslash = Employee::factory()->create(['nama_lengkap' => 'Kode\\AA', 'status_aktif' => 'Aktif']);
        Employee::factory()->create(['nama_lengkap' => 'KodeXAA', 'status_aktif' => 'Aktif']);

        foreach ([
            ['query' => 'e%A', 'id' => $percent->id],
            ['query' => 'e_A', 'id' => $underscore->id],
            ['query' => 'e\\A', 'id' => $backslash->id],
        ] as $literalQuery) {
            $this->actingAs($actor)
                ->getJson(self::LOOKUP_PATH.'?q='.urlencode($literalQuery['query']))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $literalQuery['id']);
        }
    }

    public function test_lookup_menolak_query_kosong_satu_karakter_dan_melebihi_batas(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();

        foreach (["\u{2003}\u{00A0}", 'A', str_repeat('A', 101)] as $query) {
            $this->actingAs($actor)
                ->getJson(self::LOOKUP_PATH.'?q='.urlencode($query))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('q');
        }
    }

    public function test_lookup_membatasi_hasil_ke_lima_belas_identitas(): void
    {
        Employee::factory()->count(16)->create(['nama_lengkap' => 'Referensi Penyetuju']);

        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->getJson(self::LOOKUP_PATH.'?q=Referensi')
            ->assertOk()->assertJsonCount(15, 'data');
    }
}
