<?php

namespace Tests\Feature;

use App\Models\RefJenisPegawai;
use App\Models\SkRequirement;
use App\Models\User;
use App\Support\Documents\SkCompleteness;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkRequirementMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_can_view_matrix_page(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->get(route('sk-requirements.config'))
            ->assertOk()
            ->assertSee('SK Wajib per Jenis Pegawai')
            ->assertSee('PPPK')
            ->assertSee('SK Pangkat');
    }

    public function test_non_super_admin_cannot_access_matrix_page(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->get(route('sk-requirements.config'))
            ->assertForbidden();
    }

    public function test_super_admin_can_update_matrix(): void
    {
        $user = User::factory()->superAdmin()->create();
        $pppk = RefJenisPegawai::query()->where('nama', 'PPPK')->firstOrFail();

        $this->actingAs($user)
            ->post(route('sk-requirements.update'), [
                'matrix' => [
                    $pppk->id => ['sk_pengangkatan', 'sk_kgb'],
                ],
                'reason' => 'PPPK hanya wajib 2 SK',
            ])
            ->assertRedirect(route('sk-requirements.config'));

        // PPPK: pengangkatan & kgb wajib, pangkat/jabatan tidak.
        $wajib = SkRequirement::query()
            ->where('jenis_pegawai_id', $pppk->id)
            ->where('is_wajib', true)
            ->pluck('sk_key')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['sk_kgb', 'sk_pengangkatan'], $wajib);

        // Seluruh kombinasi pool tetap tersimpan (bisa "tidak wajib" eksplisit).
        $this->assertSame(count(SkCompleteness::poolKeys()), SkRequirement::query()
            ->where('jenis_pegawai_id', $pppk->id)
            ->count());
    }
}
