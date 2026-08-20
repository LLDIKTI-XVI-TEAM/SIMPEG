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

    public function test_super_admin_sees_gear_button_on_pegawai_index(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->get('/pegawai')
            ->assertOk()
            ->assertSee('id="sk-requirement-btn"', false)
            ->assertSee('showSkRequirementModal', false);
    }

    public function test_non_super_admin_does_not_see_gear_button(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->get('/pegawai')
            ->assertOk()
            ->assertDontSee('sk-requirement-btn');
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
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Matriks SK wajib per jenis pegawai berhasil diperbarui.');

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

    public function test_non_super_admin_cannot_save_matrix(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $pppk = RefJenisPegawai::query()->where('nama', 'PPPK')->firstOrFail();

        $this->actingAs($user)
            ->post(route('sk-requirements.update'), [
                'matrix' => [$pppk->id => ['sk_pengangkatan']],
            ])
            ->assertForbidden();
    }
}
